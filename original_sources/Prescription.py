#!/usr/bin/env python
# coding: utf-8

# In[1]:


# ============================================================
# 1) IMPORTS
# ============================================================
import warnings
warnings.filterwarnings("ignore")

from pathlib import Path
import numpy as np
import pandas as pd


pd.set_option("display.max_columns", 200)
pd.set_option("display.max_rows", 200)
pd.set_option("display.float_format", lambda x: f"{x:,.4f}")


# In[2]:


# ============================================================
# DB HELPERS (MEMORY-AWARE READ/WRITE)
# ============================================================
from sqlalchemy import create_engine, text
from io import StringIO
import csv
import math
import gc
import numpy as np
import pandas as pd
from tqdm.auto import tqdm

DB_USER = "postgres"
DB_PASSWORD = "Nestle123"
DB_HOST = "127.0.0.1"
DB_PORT = "5432"
DB_NAME = "nestle_forecasting"
SCHEMA = "retail"

engine = create_engine(
    f"postgresql+psycopg2://{DB_USER}:{DB_PASSWORD}@{DB_HOST}:{DB_PORT}/{DB_NAME}",
    future=True,
    pool_pre_ping=True,
)

def standardize_columns(df):
    df = df.copy()
    rename_map = {
        "Date": "ds",
        "date": "ds",
        "month_start_date": "ds",
        "forecast": "base_forecast",
        "prediction": "base_forecast",
        "pred": "base_forecast",
        "forecast_sales": "base_forecast",
        "reconciled_pred": "reconciled_forecast",
        "mint_forecast": "reconciled_forecast",
        "trend_sales": "trend",
        "seasonal": "seasonal_multiplier",
    }
    cols = {k: v for k, v in rename_map.items() if k in df.columns and v not in df.columns}
    return df.rename(columns=cols)

def optimize_dataframe_memory(df, category_threshold=0.35):
    if df is None or df.empty:
        return df

    out = df.copy()

    for col in out.columns:
        series = out[col]
        if pd.api.types.is_integer_dtype(series):
            out[col] = pd.to_numeric(series, downcast="integer")
        elif pd.api.types.is_float_dtype(series):
            out[col] = pd.to_numeric(series, downcast="float")
        elif pd.api.types.is_object_dtype(series):
            non_null = series.dropna()
            if len(non_null) == 0:
                continue
            unique_ratio = non_null.nunique(dropna=True) / max(len(non_null), 1)
            if unique_ratio <= category_threshold:
                out[col] = series.astype("category")

    return out

def _normalize_for_sql(df):
    out = standardize_columns(df.copy())
    for c in out.columns:
        if str(out[c].dtype).startswith("datetime64"):
            out[c] = pd.to_datetime(out[c], errors="coerce")
        elif pd.api.types.is_categorical_dtype(out[c]):
            out[c] = out[c].astype("string")
    out = out.replace({pd.NA: None})
    return out

def verify_table(table_name, schema=SCHEMA):
    q = text(f'SELECT COUNT(*) AS n FROM "{schema}"."{table_name}"')
    with engine.begin() as conn:
        return int(pd.read_sql(q, conn).iloc[0, 0])

def read_sql_fast(
    query,
    parse_dates=None,
    chunksize=25000,
    label="query",
    optimize_memory=True,
):
    sql = text(query) if isinstance(query, str) else query
    frames = []

    iterator = pd.read_sql_query(
        sql,
        engine,
        parse_dates=parse_dates,
        chunksize=chunksize,
    )

    for chunk in tqdm(iterator, desc=f"Loading {label}", unit="chunk"):
        if optimize_memory:
            chunk = optimize_dataframe_memory(chunk)
        frames.append(chunk)

    if not frames:
        return pd.DataFrame()

    df = pd.concat(frames, ignore_index=True)
    del frames
    gc.collect()
    return df

def safe_read_sql(query: str, parse_dates=None, label: str = "query", chunksize=25000):
    try:
        df = read_sql_fast(
            query,
            parse_dates=parse_dates,
            chunksize=chunksize,
            label=label,
        )
        print(f"[ok] Loaded {label}: {df.shape}")
        return df
    except Exception as e:
        print(f"[skip] Could not load {label}: {e}")
        return None

def write_df_fast(df, table_name, schema=SCHEMA, if_exists="replace", chunk_size=25000):
    """Memory-aware PostgreSQL write using chunked COPY with progress bar."""
    if df is None:
        print(f"[skip] {table_name}: df is None")
        return 0
    if len(df) == 0:
        print(f"[skip] {table_name}: 0 rows")
        with engine.begin() as conn:
            conn.execute(text(f'CREATE SCHEMA IF NOT EXISTS "{schema}"'))
        return 0

    total_rows = len(df)

    with engine.begin() as conn:
        conn.execute(text(f'CREATE SCHEMA IF NOT EXISTS "{schema}"'))

    raw = None
    cur = None

    try:
        raw = engine.raw_connection()
        cur = raw.cursor()

        if if_exists == "replace":
            cur.execute(f'DROP TABLE IF EXISTS "{schema}"."{table_name}" CASCADE')
            raw.commit()

        first_chunk = _normalize_for_sql(df.iloc[: min(chunk_size, total_rows)].copy())
        first_chunk.head(0).to_sql(
            table_name,
            engine,
            schema=schema,
            if_exists="replace" if if_exists == "replace" else "append",
            index=False,
        )

        cols = ",".join([f'"{c}"' for c in first_chunk.columns])

        for start in tqdm(range(0, total_rows, chunk_size), desc=f"Writing {table_name}", unit="chunk"):
            chunk = _normalize_for_sql(df.iloc[start:start + chunk_size].copy())
            buffer = StringIO()
            chunk.to_csv(
                buffer,
                index=False,
                header=False,
                na_rep="",
                quoting=csv.QUOTE_MINIMAL,
            )
            buffer.seek(0)
            cur.copy_expert(
                f'COPY "{schema}"."{table_name}" ({cols}) FROM STDIN WITH CSV',
                buffer,
            )
            raw.commit()
            del chunk, buffer

        written = verify_table(table_name, schema=schema)
        print(f"[ok] {schema}.{table_name}: {written:,} rows written via chunked COPY")
        return written

    except Exception as e:
        if raw is not None:
            raw.rollback()
        print(f"[warn] COPY failed for {schema}.{table_name}: {e}")
        _normalize_for_sql(df.copy()).to_sql(
            table_name,
            engine,
            schema=schema,
            if_exists=if_exists,
            index=False,
            method="multi",
            chunksize=5000,
        )
        written = verify_table(table_name, schema=schema)
        print(f"[ok] {schema}.{table_name}: {written:,} rows written via chunked to_sql")
        return written

    finally:
        if cur is not None:
            cur.close()
        if raw is not None:
            raw.close()


def standardize_columns(df):
    df = df.copy()
    df.columns = (
        df.columns
        .str.strip()
        .str.lower()
        .str.replace(" ", "_")
        .str.replace(r"[^\w]", "", regex=True)
    )

    rename_map = {
        "date": "ds",
        "month_start_date": "ds",
        "productcode": "product_code",
        "storecode": "store_code",
        "forecast": "base_forecast",
        "prediction": "base_forecast",
        "pred": "base_forecast",
        "forecast_sales": "base_forecast",
        "reconciled_pred": "reconciled_forecast",
        "mint_forecast": "reconciled_forecast",
        "trend_sales": "trend",
        "seasonal": "seasonal_multiplier",
    }

    cols = {k: v for k, v in rename_map.items() if k in df.columns and v not in df.columns}
    return df.rename(columns=cols)

def restore_export_columns(df):
    df = df.copy()
    export_map = {
        "product_code": "Product Code",
        "store_code": "Store Code",
        "nestle_store_cluster": "NESTLE STORE CLUSTER",
        "nestle_region": "NESTLE REGION",
        "store_description": "Store Description",
        "product_description": "Product Description",
    }
    cols = {k: v for k, v in export_map.items() if k in df.columns}
    return df.rename(columns=cols)

def safe_pct(numerator, denominator, scale=100.0):
    numerator = pd.Series(numerator, copy=False)
    denominator = pd.Series(denominator, copy=False).replace(0, np.nan)
    return (numerator / denominator) * scale


# ## Input configuration
# This notebook is fully database-driven. All reads come from PostgreSQL, and all outputs are written back to the database.
# 

# In[3]:


# ============================================================
# 2) INPUT / OUTPUT CONFIG (DATABASE-DRIVEN)
# ============================================================
USE_C2G = True
USE_MSTL = True
USE_LGBM_FUTURE = False  # currently not used in the core prescription logic
PANEL_HISTORY_MONTHS = 18

PANEL_TABLE = f'{SCHEMA}.stg_feature_engineered_panel'
MINT_TABLE = f'{SCHEMA}.stg_mint_reconciled_forecast'
C2G_TABLE = f'{SCHEMA}.mart_c2g_bottom_level'
MSTL_TABLE = f'{SCHEMA}.stg_mstl_decomposition_for_lightgbm'
LGBM_FUTURE_TABLE = f'{SCHEMA}.stg_lightgbm_dashboard_forecast'

OUTPUT_TABLES = {
    'prescription_main': 'api_prescription_actions',
    'prescription_expand': 'mart_prescription_expand',
    'prescription_maintain': 'mart_prescription_maintain',
    'prescription_deprioritize': 'mart_prescription_deprioritize',
    'implementation_guide': 'api_prescription_implementation_guide',
}

print('Panel table      :', PANEL_TABLE)
print('MinT table       :', MINT_TABLE)
print('C2G table        :', C2G_TABLE if USE_C2G else 'disabled')
print('MSTL table       :', MSTL_TABLE if USE_MSTL else 'disabled')
print('LGBM future table:', LGBM_FUTURE_TABLE if USE_LGBM_FUTURE else 'disabled')


# In[4]:


# ============================================================
# 3) LOAD INPUTS FROM DATABASE
#    Optimized to load only relevant rows and narrower columns
# ============================================================

mint_query = f"""
SELECT
    ds,
    product_code,
    store_code,
    nestle_store_cluster,
    nestle_region,
    reconciled_forecast
FROM {MINT_TABLE}
"""

panel_query = f"""
SELECT
    p.ds,
    p."Product Code",
    p."Product Description",
    p."NESTLE STORE CLUSTER",
    p."NESTLE REGION",
    p.net_sales_ty,
    p."Brand",
    p."Category"
FROM {PANEL_TABLE} p
INNER JOIN (
    SELECT DISTINCT
        product_code
    FROM {MINT_TABLE}
) m
    ON p."Product Code" = m.product_code
WHERE p.ds >= CURRENT_DATE - INTERVAL '{PANEL_HISTORY_MONTHS} months'
"""

c2g_query = f"""
SELECT
    ds,
    "Product Code",
    "NESTLE STORE CLUSTER",
    "NESTLE REGION",
    c2g_vs_ly,
    actual_ly,
    actual_last
FROM {C2G_TABLE}
"""

mstl_query = f"""
SELECT
    ds,
    "Product Code",
    trend
FROM {MSTL_TABLE}
WHERE ds >= CURRENT_DATE - INTERVAL '{PANEL_HISTORY_MONTHS} months'
"""

mint = read_sql_fast(mint_query, parse_dates=["ds"], label="mint")
panel = read_sql_fast(panel_query, parse_dates=["ds"], label="panel")
c2g_bottom = safe_read_sql(c2g_query, parse_dates=["ds"], label="c2g_bottom") if USE_C2G else None
mstl_decomp = safe_read_sql(mstl_query, parse_dates=["ds"], label="mstl_decomp") if USE_MSTL else None
lgbm_future = None

print('panel shape      :', panel.shape)
print('mint shape       :', mint.shape)
print('c2g shape        :', None if c2g_bottom is None else c2g_bottom.shape)
print('mstl shape       :', None if mstl_decomp is None else mstl_decomp.shape)
print('lgbm future shape:', None if lgbm_future is None else lgbm_future.shape)

print('Original panel columns:')
print(panel.columns.tolist())
print('Original mint columns:')
print(mint.columns.tolist())

display(panel.head(2))
display(mint.head(2))
if c2g_bottom is not None:
    display(c2g_bottom.head(2))
if mstl_decomp is not None:
    display(mstl_decomp.head(2))


# In[5]:


# ============================================================
# 4) HELPER FUNCTIONS
# ============================================================
def pick_col(df, candidates, required=True, label=None):
    for c in candidates:
        if c in df.columns:
            return c
    if required:
        raise KeyError(f"Missing required column for {label or candidates}: {candidates}")
    return None

def safe_pct(numerator, denominator):
    denominator = np.where(np.abs(denominator) < 1e-9, np.nan, denominator)
    return (numerator / denominator) * 100.0

def money_fmt(x):
    if pd.isna(x):
        return "—"
    return f"₱{x:,.2f}"

def pct_fmt(x):
    if pd.isna(x):
        return "—"
    return f"{x:+.2f}%"

def int_fmt(x):
    if pd.isna(x):
        return "—"
    return f"{int(round(x)):,}"

def clipped_share(series):
    total = series.sum()
    if abs(total) < 1e-9:
        return pd.Series(np.zeros(len(series)), index=series.index)
    return (series / total) * 100.0

REGION_MAP = {
    "SL": "South Luzon",
    "SOUTH LUZON": "South Luzon",
    "NCL": "North Luzon",
    "NL": "North Luzon",
    "NORTH LUZON": "North Luzon",
    "GMA": "GMA",
    "VIS": "Visayas",
    "VISAYAS": "Visayas",
    "MIN": "Mindanao",
    "MINDANAO": "Mindanao",
}

CLUSTER_MAP = {
    "COMMERCIAL": "Commercial",
    "COMMUNITY": "Community",
    "HEALTH CARE FACILITY": "Health Care Facility",
    "HCF": "Health Care Facility",
    "MALL": "Mall",
}

def normalize_string(s):
    return (
        s.astype("string")
         .str.strip()
         .replace({"": pd.NA, "nan": pd.NA, "None": pd.NA, "<NA>": pd.NA})
    )

def standardize_region(s):
    s = normalize_string(s).str.upper()
    return s.map(REGION_MAP).fillna(s.str.title())

def standardize_cluster(s):
    s = normalize_string(s).str.upper()
    return s.map(CLUSTER_MAP).fillna(s.str.title())

def standardize_code(s, zfill=None):
    out = normalize_string(s)
    out = out.str.replace(r"\.0$", "", regex=True)
    if zfill is not None:
        out = out.str.zfill(zfill)
    return out

def fill_product_desc_from_code(df, product_col, product_desc_col):
    if product_desc_col is None or product_desc_col not in df.columns:
        return df
    temp = df.copy()
    temp[product_desc_col] = normalize_string(temp[product_desc_col])
    temp[product_desc_col] = temp[product_desc_col].fillna(temp[product_col])
    return temp


# In[6]:


# ============================================================
# 5) STANDARDIZE KEY COLUMNS
# ============================================================
# avoid unnecessary full DataFrame copies here; standardize in place

panel_ds = pick_col(panel, ["ds", "date"], label="panel ds")
mint_ds  = pick_col(mint,  ["ds", "date"], label="mint ds")

panel[panel_ds] = pd.to_datetime(panel[panel_ds])
mint[mint_ds]   = pd.to_datetime(mint[mint_ds])

if c2g_bottom is not None:
    c2g_ds = pick_col(c2g_bottom, ["ds", "date"], label="c2g ds")
    c2g_bottom[c2g_ds] = pd.to_datetime(c2g_bottom[c2g_ds])

if mstl_decomp is not None:
    mstl_ds = pick_col(mstl_decomp, ["ds", "date"], label="mstl ds")
    mstl_decomp[mstl_ds] = pd.to_datetime(mstl_decomp[mstl_ds])

if lgbm_future is not None:
    lgbm_ds = pick_col(lgbm_future, ["ds", "date"], label="lgbm ds")
    lgbm_future[lgbm_ds] = pd.to_datetime(lgbm_future[lgbm_ds])

prod_code_col = pick_col(panel, ["Product Code", "product_code"], label="product code")
prod_desc_col = pick_col(panel, ["Product Description", "product_description"], required=False, label="product desc")
store_code_col = pick_col(panel, ["Store Code", "store_code"], required=False, label="store code")
store_desc_col = pick_col(panel, ["Store Description", "store_description"], required=False, label="store desc")
region_col = pick_col(panel, ["NESTLE REGION", "nestle_region", "Region"], required=False, label="region")
cluster_col = pick_col(panel, ["NESTLE STORE CLUSTER", "nestle_store_cluster", "Cluster"], required=False, label="cluster")
brand_col = pick_col(panel, ["Brand", "brand"], required=False, label="brand")
category_col = pick_col(panel, ["Category", "category"], required=False, label="category")

sales_col = pick_col(panel, ["net_sales_ty", "Net Sales TY", "sales_ty"], label="current sales")

mint_prod_code_col = pick_col(mint, ["Product Code", "product_code"], label="mint product code")
mint_region_col = pick_col(mint, ["NESTLE REGION", "nestle_region", "Region"], required=False, label="mint region")
mint_cluster_col = pick_col(mint, ["NESTLE STORE CLUSTER", "nestle_store_cluster", "Cluster"], required=False, label="mint cluster")
mint_store_code_col = pick_col(mint, ["Store Code", "store_code"], required=False, label="mint store code")
mint_forecast_col = pick_col(mint, ["reconciled_forecast", "base_forecast", "forecast_net_sales_ty", "forecast"], label="mint forecast")

# Standardize panel
panel[prod_code_col] = standardize_code(panel[prod_code_col], zfill=9)
if store_code_col is not None:
    panel[store_code_col] = standardize_code(panel[store_code_col])
if region_col is not None:
    panel[region_col] = standardize_region(panel[region_col])
if cluster_col is not None:
    panel[cluster_col] = standardize_cluster(panel[cluster_col])
if prod_desc_col is not None:
    panel[prod_desc_col] = normalize_string(panel[prod_desc_col])
if store_desc_col is not None:
    panel[store_desc_col] = normalize_string(panel[store_desc_col])
if brand_col is not None:
    panel[brand_col] = normalize_string(panel[brand_col])
if category_col is not None:
    panel[category_col] = normalize_string(panel[category_col])
panel = fill_product_desc_from_code(panel, prod_code_col, prod_desc_col)

# Standardize mint
mint[mint_prod_code_col] = standardize_code(mint[mint_prod_code_col], zfill=9)
if mint_store_code_col is not None:
    mint[mint_store_code_col] = standardize_code(mint[mint_store_code_col])
if mint_region_col is not None:
    mint[mint_region_col] = standardize_region(mint[mint_region_col])
if mint_cluster_col is not None:
    mint[mint_cluster_col] = standardize_cluster(mint[mint_cluster_col])

# Standardize optional inputs
if c2g_bottom is not None:
    c2g_prod_code_col = pick_col(c2g_bottom, ["Product Code", "product_code"], label="c2g product code")
    c2g_region_col = pick_col(c2g_bottom, ["NESTLE REGION", "nestle_region", "Region"], required=False, label="c2g region")
    c2g_cluster_col = pick_col(c2g_bottom, ["NESTLE STORE CLUSTER", "nestle_store_cluster", "Cluster"], required=False, label="c2g cluster")
    c2g_store_code_col = pick_col(c2g_bottom, ["Store Code", "store_code"], required=False, label="c2g store code")
    c2g_bottom[c2g_prod_code_col] = standardize_code(c2g_bottom[c2g_prod_code_col], zfill=9)
    if c2g_store_code_col is not None:
        c2g_bottom[c2g_store_code_col] = standardize_code(c2g_bottom[c2g_store_code_col])
    if c2g_region_col is not None:
        c2g_bottom[c2g_region_col] = standardize_region(c2g_bottom[c2g_region_col])
    if c2g_cluster_col is not None:
        c2g_bottom[c2g_cluster_col] = standardize_cluster(c2g_bottom[c2g_cluster_col])

if mstl_decomp is not None:
    mstl_prod_code_col = pick_col(mstl_decomp, ["Product Code", "product_code"], label="mstl product code")
    mstl_trend_col = pick_col(mstl_decomp, ["trend", "mstl_trend"], label="mstl trend")
    mstl_decomp[mstl_prod_code_col] = standardize_code(mstl_decomp[mstl_prod_code_col], zfill=9)

if lgbm_future is not None:
    lgbm_prod_code_col = pick_col(lgbm_future, ["Product Code", "product_code"], label="lgbm product code")
    lgbm_region_col = pick_col(lgbm_future, ["NESTLE REGION", "nestle_region", "Region"], required=False, label="lgbm region")
    lgbm_cluster_col = pick_col(lgbm_future, ["NESTLE STORE CLUSTER", "nestle_store_cluster", "Cluster"], required=False, label="lgbm cluster")
    lgbm_store_code_col = pick_col(lgbm_future, ["Store Code", "store_code"], required=False, label="lgbm store code")
    lgbm_forecast_col = pick_col(lgbm_future, ["forecast_net_sales_ty", "base_forecast", "forecast"], label="lgbm forecast")
    lgbm_future[lgbm_prod_code_col] = standardize_code(lgbm_future[lgbm_prod_code_col], zfill=9)
    if lgbm_store_code_col is not None:
        lgbm_future[lgbm_store_code_col] = standardize_code(lgbm_future[lgbm_store_code_col])
    if lgbm_region_col is not None:
        lgbm_future[lgbm_region_col] = standardize_region(lgbm_future[lgbm_region_col])
    if lgbm_cluster_col is not None:
        lgbm_future[lgbm_cluster_col] = standardize_cluster(lgbm_future[lgbm_cluster_col])

SEGMENT_KEYS = [c for c in [prod_code_col, region_col, cluster_col] if c is not None]
MINT_SEGMENT_KEYS = [c for c in [mint_prod_code_col, mint_region_col, mint_cluster_col] if c is not None]

print("Prescription segment keys:", SEGMENT_KEYS)
print("Unique panel regions   :", sorted(panel[region_col].dropna().unique().tolist()) if region_col else "n/a")
print("Unique mint regions    :", sorted(mint[mint_region_col].dropna().unique().tolist()) if mint_region_col else "n/a")
print("Unique panel clusters  :", sorted(panel[cluster_col].dropna().unique().tolist()) if cluster_col else "n/a")
print("Unique mint clusters   :", sorted(mint[mint_cluster_col].dropna().unique().tolist()) if mint_cluster_col else "n/a")


# In[7]:


# ============================================================
# 6) BUILD HISTORY SNAPSHOT
#    Uses latest actual month and same month last year
#    Trend g_T is sourced from MSTL trend output
#    Current Sales is sourced from FE panel actuals
# ============================================================

# Stable lookup at prescription grain
lookup_group = [c for c in [prod_code_col, region_col, cluster_col] if c is not None]
lookup_cols = [c for c in [prod_code_col, prod_desc_col, region_col, cluster_col, brand_col, category_col] if c is not None]
segment_lookup = (
    panel[lookup_cols]
    .drop_duplicates()
    .groupby(lookup_group, as_index=False, observed=True)
    .last()
)

latest_actual_ds = panel[panel_ds].max()
ly_same_month_ds = latest_actual_ds - pd.DateOffset(years=1)

print("Latest actual month:", latest_actual_ds.date())
print("LY same month      :", ly_same_month_ds.date())

sales_hist = (
    panel.groupby(SEGMENT_KEYS + [panel_ds], as_index=False, observed=True)
    .agg(current_sales=(sales_col, "sum"))
)

if mstl_decomp is not None:
    mstl_attach = (
        mstl_decomp[[mstl_prod_code_col, mstl_ds, mstl_trend_col]]
        .rename(columns={
            mstl_prod_code_col: prod_code_col,
            mstl_ds: panel_ds,
            mstl_trend_col: "mstl_trend"
        })
        .drop_duplicates(subset=[prod_code_col, panel_ds])
    )
    trend_hist = (
        mstl_attach.groupby([prod_code_col, panel_ds], as_index=False, observed=True)
        .agg(current_trend=("mstl_trend", "sum"))
    )
    hist_agg = sales_hist.merge(trend_hist, on=[prod_code_col, panel_ds], how="left", validate="many_to_one")
    del mstl_attach, trend_hist
else:
    hist_agg = sales_hist.copy()
    hist_agg["current_trend"] = np.nan

del sales_hist
gc.collect()

current_actual = hist_agg.loc[
    hist_agg[panel_ds] == latest_actual_ds,
    SEGMENT_KEYS + ["current_sales", "current_trend"]
].copy()

ly_actual = hist_agg.loc[
    hist_agg[panel_ds] == ly_same_month_ds,
    SEGMENT_KEYS + ["current_sales", "current_trend"]
].rename(columns={
    "current_sales": "ly_sales",
    "current_trend": "ly_trend"
}).copy()

history_snapshot = current_actual.merge(ly_actual, on=SEGMENT_KEYS, how="left")
history_snapshot = history_snapshot.merge(segment_lookup, on=SEGMENT_KEYS, how="left", validate="many_to_one")

history_snapshot["product_label"] = (
    history_snapshot[prod_desc_col].astype("string")
    if prod_desc_col is not None and prod_desc_col in history_snapshot.columns
    else history_snapshot[prod_code_col].astype("string")
)
history_snapshot["product_label"] = history_snapshot["product_label"].fillna(history_snapshot[prod_code_col].astype("string"))

print("History snapshot rows:", len(history_snapshot))
display(history_snapshot.head())

del hist_agg, current_actual, ly_actual, segment_lookup
gc.collect()


# In[8]:


# ============================================================
# 7) BUILD LATEST FORECAST SNAPSHOT
#    Forecast truth for prescription comes from MinT
#    (coherent forecast after reconciliation)
# ============================================================
latest_fcst_ds = mint[mint_ds].max()

forecast_agg = (
    mint.groupby(MINT_SEGMENT_KEYS + [mint_ds], as_index=False, observed=True)[mint_forecast_col]
    .sum()
)

latest_forecast = (
    forecast_agg.loc[forecast_agg[mint_ds] == latest_fcst_ds, MINT_SEGMENT_KEYS + [mint_forecast_col]]
    .rename(columns={mint_forecast_col: "recent_forecast"})
    .copy()
)

rename_map = {}
if mint_prod_code_col != prod_code_col:
    rename_map[mint_prod_code_col] = prod_code_col
if mint_region_col is not None and region_col is not None and mint_region_col != region_col:
    rename_map[mint_region_col] = region_col
if mint_cluster_col is not None and cluster_col is not None and mint_cluster_col != cluster_col:
    rename_map[mint_cluster_col] = cluster_col

latest_forecast = latest_forecast.rename(columns=rename_map)
latest_forecast["forecast_month"] = latest_fcst_ds

print("Latest forecast month:", latest_fcst_ds.date())
print("Latest forecast rows :", len(latest_forecast))
display(latest_forecast.head())


# In[9]:


# ============================================================
# 8) BUILD PRESCRIPTION METRICS
# ============================================================
prescription = latest_forecast.merge(history_snapshot, on=SEGMENT_KEYS, how="left")

# Merge C2G if available
if c2g_bottom is not None:
    c2g_value_col = pick_col(c2g_bottom, ["c2g_vs_ly", "c2g_pct", "c2g"], required=False, label="c2g value")
    c2g_ly_col = pick_col(c2g_bottom, ["actual_ly", "ly_sales"], required=False, label="c2g ly actual")
    c2g_last_col = pick_col(c2g_bottom, ["actual_last", "current_sales"], required=False, label="c2g last actual")

    c2g_group_cols = [c for c in [c2g_prod_code_col, c2g_region_col, c2g_cluster_col, c2g_ds] if c is not None]
    c2g_agg_dict = {}
    if c2g_value_col is not None:
        c2g_agg_dict[c2g_value_col] = "sum"
    if c2g_ly_col is not None:
        c2g_agg_dict[c2g_ly_col] = "sum"
    if c2g_last_col is not None:
        c2g_agg_dict[c2g_last_col] = "sum"

    c2g_latest = (
        c2g_bottom.loc[c2g_bottom[c2g_ds] == latest_fcst_ds]
        .groupby(c2g_group_cols[:-1], as_index=False, observed=True)
        .agg(c2g_agg_dict)
        .copy()
    )

    c2g_rename = {}
    if c2g_prod_code_col != prod_code_col:
        c2g_rename[c2g_prod_code_col] = prod_code_col
    if c2g_region_col is not None and region_col is not None and c2g_region_col != region_col:
        c2g_rename[c2g_region_col] = region_col
    if c2g_cluster_col is not None and cluster_col is not None and c2g_cluster_col != cluster_col:
        c2g_rename[c2g_cluster_col] = cluster_col
    if c2g_value_col is not None:
        c2g_rename[c2g_value_col] = "_c2g_raw"
    if c2g_ly_col is not None:
        c2g_rename[c2g_ly_col] = "_c2g_ly_sales"
    if c2g_last_col is not None:
        c2g_rename[c2g_last_col] = "_c2g_current_sales"

    c2g_latest = c2g_latest.rename(columns=c2g_rename)

    prescription = prescription.merge(
        c2g_latest,
        on=SEGMENT_KEYS,
        how="left",
        validate="one_to_one"
    )

    if "_c2g_current_sales" in prescription.columns:
        prescription["current_sales"] = prescription["current_sales"].fillna(prescription["_c2g_current_sales"])
    if "_c2g_ly_sales" in prescription.columns:
        prescription["ly_sales"] = prescription["ly_sales"].fillna(prescription["_c2g_ly_sales"])

# Derive core metrics
prescription["growth_value"] = prescription["recent_forecast"] - prescription["ly_sales"].fillna(0)

# Forecast g_F = forecast vs latest actual current sales
prescription["forecast_g_F_pct"] = safe_pct(
    prescription["recent_forecast"] - prescription["current_sales"],
    prescription["current_sales"]
)

# Trend g_T = current MSTL trend vs same month last year MSTL trend
prescription["trend_g_T_pct"] = safe_pct(
    prescription["current_trend"] - prescription["ly_trend"],
    prescription["ly_trend"]
)

# C2G %
if "_c2g_raw" in prescription.columns:
    prescription["c2g_pct"] = pd.to_numeric(prescription["_c2g_raw"], errors="coerce") * 100.0
else:
    prescription["c2g_pct"] = clipped_share(prescription["growth_value"].fillna(0))

prescription["abs_c2g_pct"] = prescription["c2g_pct"].abs()

# Fill product label / metadata
if "product_label" not in prescription.columns:
    if prod_desc_col is not None and prod_desc_col in prescription.columns:
        prescription["product_label"] = prescription[prod_desc_col].astype("string")
    else:
        prescription["product_label"] = prescription[prod_code_col].astype("string")

prescription["product_label"] = prescription["product_label"].fillna(prescription[prod_code_col].astype("string"))

if brand_col is not None and brand_col in prescription.columns:
    prescription[brand_col] = prescription[brand_col].astype("string").fillna("Unknown")
else:
    prescription["Brand"] = "Unknown"
    brand_col = "Brand"

if category_col is not None and category_col in prescription.columns:
    prescription[category_col] = prescription[category_col].astype("string").fillna("Unknown")
else:
    prescription["Category"] = "Unknown"
    category_col = "Category"

segment_parts = ["product_label"]
if region_col is not None:
    segment_parts.append(region_col)
if cluster_col is not None:
    segment_parts.append(cluster_col)

prescription["segment"] = prescription[segment_parts].astype(str).agg(" / ".join, axis=1)
prescription["forecast_month"] = latest_fcst_ds
prescription["actual_month"] = latest_actual_ds

coverage = pd.Series({
    "current_sales_missing_rate": prescription["current_sales"].isna().mean(),
    "ly_sales_missing_rate": prescription["ly_sales"].isna().mean(),
    "trend_g_T_missing_rate": prescription["trend_g_T_pct"].isna().mean(),
    "forecast_g_F_missing_rate": prescription["forecast_g_F_pct"].isna().mean(),
    "c2g_missing_rate": prescription["c2g_pct"].isna().mean(),
})
print("Metric coverage after fix:")
display(coverage.to_frame("missing_rate"))

display(prescription.head())


# In[10]:


# ============================================================
# 9) THESIS-ALIGNED RULE ENGINE
#    Uses the E1–D4 prescription matrix from the thesis paper
# ============================================================
TREND_POS = 3.0
TREND_NEG = -3.0
FORECAST_POS = 3.0
FORECAST_NEG = -3.0
C2G_HIGH = 2.0
C2G_NEAR_ZERO_BAND = 0.5

# Rank contribution strength for thesis logic
prescription["c2g_rank_desc"] = prescription["c2g_pct"].fillna(-999).rank(method="first", ascending=False, pct=True)
prescription["c2g_rank_asc"] = prescription["c2g_pct"].fillna(999).rank(method="first", ascending=True, pct=True)
prescription["is_top_25"] = prescription["c2g_rank_desc"] <= 0.25
prescription["is_bottom_25"] = prescription["c2g_rank_asc"] <= 0.25
prescription["abs_c2g_pct"] = prescription["c2g_pct"].abs()


def classify_row(r):
    t = r.get("trend_g_T_pct", np.nan)
    f = r.get("forecast_g_F_pct", np.nan)
    c = r.get("c2g_pct", np.nan)
    top25 = bool(r.get("is_top_25", False))
    bottom25 = bool(r.get("is_bottom_25", False))

    t_pos = pd.notna(t) and t >= TREND_POS
    t_neg = pd.notna(t) and t <= TREND_NEG
    t_neutral = pd.notna(t) and (TREND_NEG < t < TREND_POS)

    f_pos = pd.notna(f) and f >= FORECAST_POS
    f_neg = pd.notna(f) and f <= FORECAST_NEG
    f_neutral = pd.notna(f) and (FORECAST_NEG < f < FORECAST_POS)

    c_pos = pd.notna(c) and c > 0
    c_neg = pd.notna(c) and c < 0
    c_high = pd.notna(c) and (c >= C2G_HIGH or top25)
    c_low_neutral = pd.notna(c) and (0 <= c < C2G_HIGH) and (not top25)
    c_near_zero = pd.notna(c) and abs(c) < C2G_NEAR_ZERO_BAND
    c_bottom_tier = pd.notna(c) and (c_neg or bottom25)

    # -------------------------------
    # EXPAND
    # -------------------------------
    if t_pos and f_pos and c_high:
        return pd.Series({
            "condition_id": "E1",
            "condition": "E1",
            "action": "Expand",
            "prescription_label": "Expand",
            "priority_bucket": 1,
            "action_guidance": "Increase allocation; prioritize top replenishment opportunities; ensure shelf availability.",
        })

    if t_pos and f_pos and c_low_neutral:
        return pd.Series({
            "condition_id": "E2",
            "condition": "E2",
            "action": "Expand",
            "prescription_label": "Expand (Selective)",
            "priority_bucket": 2,
            "action_guidance": "Expand selectively; target the best SKUs or brands inside the segment.",
        })

    # -------------------------------
    # MAINTAIN
    # -------------------------------
    if t_neutral and f_pos and c_pos:
        return pd.Series({
            "condition_id": "M1",
            "condition": "M1",
            "action": "Maintain",
            "prescription_label": "Maintain → Watch",
            "priority_bucket": 3,
            "action_guidance": "Hold steady and monitor the next 1 to 2 cycles for confirmation.",
        })

    if t_pos and f_neutral and c_pos:
        return pd.Series({
            "condition_id": "M2",
            "condition": "M2",
            "action": "Maintain",
            "prescription_label": "Maintain",
            "priority_bucket": 4,
            "action_guidance": "Keep stable levels and avoid overreacting because the trend is good but the forecast is flat.",
        })

    if t_neutral and f_neutral and c_near_zero:
        return pd.Series({
            "condition_id": "M3",
            "condition": "M3",
            "action": "Maintain",
            "prescription_label": "Maintain",
            "priority_bucket": 5,
            "action_guidance": "No major change; review again in the next cycle.",
        })

    # -------------------------------
    # DECLINE / CONFLICT / REBOUND
    # -------------------------------
    if t_neg and f_neg and c_bottom_tier:
        return pd.Series({
            "condition_id": "D1",
            "condition": "D1",
            "action": "De-prioritize",
            "prescription_label": "De-prioritize",
            "priority_bucket": 8,
            "action_guidance": "Reduce allocation; rebalance inventory; tighten replenishment; avoid unnecessary restock.",
        })

    if t_neg and f_neg and c_pos:
        return pd.Series({
            "condition_id": "D2",
            "condition": "D2",
            "action": "Maintain",
            "prescription_label": "Maintain (Investigate)",
            "priority_bucket": 6,
            "action_guidance": "Overall signals are declining but the segment still contributes positively; investigate substitution, distribution, or local demand shifts.",
        })

    if t_pos and f_neg:
        return pd.Series({
            "condition_id": "D3",
            "condition": "D3",
            "action": "Maintain",
            "prescription_label": "Maintain (Conflict)",
            "priority_bucket": 6,
            "action_guidance": "Signals conflict; hold steady, check for shocks or stockouts, and reassess next update.",
        })

    if t_neg and f_pos:
        return pd.Series({
            "condition_id": "D4",
            "condition": "D4",
            "action": "Maintain",
            "prescription_label": "Maintain (Rebound)",
            "priority_bucket": 6,
            "action_guidance": "Possible rebound; keep steady and confirm next month before cutting allocation.",
        })

    # Conservative fallback when rows do not fit cleanly
    return pd.Series({
        "condition_id": "M3",
        "condition": "M3",
        "action": "Maintain",
        "prescription_label": "Maintain",
        "priority_bucket": 7,
        "action_guidance": "Mixed or incomplete signals; hold steady and review in the next cycle.",
    })


rule_out = prescription.apply(classify_row, axis=1)
prescription = pd.concat([prescription, rule_out], axis=1)

# Score for within-bucket ordering only
prescription["priority_score"] = (
    prescription["forecast_g_F_pct"].fillna(0) * 0.40 +
    prescription["trend_g_T_pct"].fillna(0) * 0.30 +
    prescription["c2g_pct"].fillna(0) * 0.30
)

prescription = prescription.sort_values(
    ["priority_bucket", "priority_score", "recent_forecast"],
    ascending=[True, False, False]
).reset_index(drop=True)

prescription["priority"] = np.arange(1, len(prescription) + 1)

print("Condition counts:")
display(prescription["condition_id"].value_counts(dropna=False).rename_axis("Condition ID").to_frame("Rows"))
display(prescription.head(10))



# In[11]:


# ============================================================
# 10) PREPARE DB EXPORT OUTPUTS
# ============================================================
export_cols = [
    "priority",
    "condition_id",
    "condition",
    "segment",
    "action",
    "prescription_label",
    "trend_g_T_pct",
    "forecast_g_F_pct",
    "c2g_pct",
    "current_sales",
    "recent_forecast",
    "ly_sales",
    "growth_value",
    "is_top_25",
    "is_bottom_25",
    "action_guidance",
    brand_col,
    category_col,
    prod_code_col,
    prod_desc_col,
    region_col,
    cluster_col,
]
export_cols = [c for c in export_cols if c is not None and c in prescription.columns]
prescription_export = prescription.loc[:, export_cols].copy()

action_col = prescription_export["action"].astype("string")
expand_df = prescription_export.loc[action_col == "Expand"].copy()
maintain_df = prescription_export.loc[action_col == "Maintain"].copy()
deprio_df = prescription_export.loc[action_col == "De-prioritize"].copy()
del action_col
gc.collect()

print('Prepared DB outputs:')
print('prescription_export:', prescription_export.shape)
print('expand_df          :', expand_df.shape)
print('maintain_df        :', maintain_df.shape)
print('deprio_df          :', deprio_df.shape)


# ## Optional filtered views
# These filtered prescription subsets are prepared here and written to the database in the export section below.
# 

# In[12]:


# Optional filtered view counts for dashboard / downstream apps
print('Filtered views ready for DB export:')
print('Expand rows       :', len(expand_df))
print('Maintain rows     :', len(maintain_df))
print('De-prioritize rows:', len(deprio_df))


# ## DB export
# The next cell writes the main prescription table, filtered action tables, and the implementation guide directly to PostgreSQL.
# 

# In[13]:


# ============================================================
# DB EXPORTS: PRESCRIPTION + IMPLEMENTATION GUIDE (FINAL)
# ============================================================
from tqdm import tqdm
from sqlalchemy import text

def _sanitize_for_db(df):
    out = standardize_columns(df.copy())
    out.columns = [str(c).replace('%', 'pct').replace(' ', '_') for c in out.columns]
    return out

def create_empty_table_like(df, table_name, schema="retail"):
    type_map = {
        "object": "TEXT",
        "string": "TEXT",
        "int64": "BIGINT",
        "int32": "INTEGER",
        "float64": "DOUBLE PRECISION",
        "float32": "REAL",
        "bool": "BOOLEAN",
        "datetime64[ns]": "TIMESTAMP",
    }

    cols_sql = []
    for col, dtype in df.dtypes.items():
        dtype_str = str(dtype)
        sql_type = type_map.get(dtype_str, "TEXT")
        cols_sql.append(f'"{col}" {sql_type}')

    create_sql = f'''
    DROP TABLE IF EXISTS "retail"."{table_name}";
    CREATE TABLE "retail"."{table_name}" (
        {", ".join(cols_sql)}
    );
    '''

    with engine.begin() as conn:
        conn.execute(text(create_sql))

    print(f'[ok] retail.{table_name}: 0 rows (empty table created)')


implementation_guide = pd.DataFrame([
    {"sort_order": 1, "action": "Expand", "recommended_steps": "Increase allocation, prioritize replenishment, protect shelf availability.", "timeline": "Immediate (Week 1-2)"},
    {"sort_order": 2, "action": "Expand (Selective)", "recommended_steps": "Target best SKUs or brands inside the segment.", "timeline": "Immediate (Week 1-2)"},
    {"sort_order": 3, "action": "Maintain → Watch", "recommended_steps": "Hold steady and monitor the next 1 to 2 cycles.", "timeline": "Within 1 cycle"},
    {"sort_order": 4, "action": "Maintain", "recommended_steps": "Keep current allocation stable and support with seasonal promotions when needed.", "timeline": "Ongoing"},
    {"sort_order": 5, "action": "Maintain (Investigate)", "recommended_steps": "Hold steady while checking substitutions, distribution gaps, or local shifts.", "timeline": "Within 1 cycle"},
    {"sort_order": 6, "action": "De-prioritize", "recommended_steps": "Reduce allocation gradually and clear low-performing inventory.", "timeline": "Gradual (Week 3-4)"},
])

export_sequence = [
    (OUTPUT_TABLES['prescription_main'], prescription_export),
    (OUTPUT_TABLES['prescription_expand'], expand_df),
    (OUTPUT_TABLES['prescription_maintain'], maintain_df),
    (OUTPUT_TABLES['prescription_deprioritize'], deprio_df),
    (OUTPUT_TABLES['implementation_guide'], implementation_guide),
]

with tqdm(total=len(export_sequence), desc='Writing prescription outputs', unit='table') as pbar:
    for table_name, df_out in export_sequence:

        # implementation guide → always write
        if table_name == OUTPUT_TABLES['implementation_guide']:
            write_df_fast(df_out, table_name)

        else:
            df_clean = _sanitize_for_db(df_out)

            if df_clean.empty:
                create_empty_table_like(df_clean, table_name)
            else:
                write_df_fast(df_clean, table_name)

        pbar.update(1)
        gc.collect()


# In[14]:


# ============================================================
# Verification (safe)
# ============================================================
for table_name in OUTPUT_TABLES.values():
    try:
        print(f"{table_name} rows:", verify_table(table_name))
    except Exception as e:
        print(f"{table_name} ERROR:", e)


# ## Added DB-only/frontend contract alignment

# In[15]:


# Exact frontend contract check
print("api_prescription_actions rows:", verify_table("api_prescription_actions"))
print("api_prescription_implementation_guide rows:", verify_table("api_prescription_implementation_guide"))


# In[ ]:




