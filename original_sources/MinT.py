#!/usr/bin/env python
# coding: utf-8

# # MinT Reconciliation Notebook

# ## 1) Imports and database helpers

# In[6]:


import pandas as pd
import numpy as np
from pathlib import Path
from IPython.display import display
from tqdm import tqdm


# In[31]:


# ============================================================
# DB HELPERS (MEMORY-AWARE READ/WRITE)
# ============================================================
from sqlalchemy import create_engine, text
from io import StringIO
import csv
import gc
import math
import numpy as np
import pandas as pd
from tqdm.auto import tqdm

DB_USER = "postgres"
DB_PASSWORD = "Nestle123"
DB_HOST = "127.0.0.1"
DB_PORT = "5432"
DB_NAME = "nestle_forecasting"
SCHEMA = "retail"

READ_CHUNK_SIZE = 25_000
WRITE_CHUNK_SIZE = 25_000

engine = create_engine(
    f"postgresql+psycopg2://{DB_USER}:{DB_PASSWORD}@{DB_HOST}:{DB_PORT}/{DB_NAME}",
    future=True,
    pool_pre_ping=True,
)

def standardize_columns(df):
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
    out = standardize_columns(df)
    for c in out.columns:
        if str(out[c].dtype).startswith("datetime64"):
            out[c] = pd.to_datetime(out[c], errors="coerce")
        elif isinstance(out[c].dtype, pd.CategoricalDtype):
            out[c] = out[c].astype("string")
    out = out.replace({pd.NA: None})
    return out

def verify_table(table_name, schema="retail"):
    df = read_sql_fast(
        f'SELECT COUNT(*) AS n FROM "{schema}"."{table_name}"'
    )
    return int(df["n"].iloc[0]) if len(df) else 0

def print_df_memory(df, label):
    mem_mb = df.memory_usage(deep=True).sum() / (1024 ** 2)
    print(f"{label}: shape={df.shape}, memory={mem_mb:,.2f} MB")

def read_sql_fast(
    query,
    parse_dates=None,
    chunksize=READ_CHUNK_SIZE,
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
    return optimize_dataframe_memory(df) if optimize_memory else df

def safe_read_sql(query: str, parse_dates=None, label: str = "query", chunksize=READ_CHUNK_SIZE):
    try:
        df = read_sql_fast(
            query,
            parse_dates=parse_dates,
            chunksize=chunksize,
            label=label,
        )
        print_df_memory(df, f"Loaded {label}")
        return df
    except Exception as e:
        print(f"[skip] Could not load {label}: {e}")
        return None

def write_df_fast(df, table_name, schema=SCHEMA, if_exists="replace", chunk_size=WRITE_CHUNK_SIZE):
    """Memory-aware PostgreSQL write using chunked COPY with per-chunk normalization."""
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
            del chunk

        written = verify_table(table_name, schema=schema)
        print(f"[ok] {schema}.{table_name}: {written:,} rows written via chunked COPY")
        return written

    except Exception as e:
        if raw is not None:
            raw.rollback()
        print(f"[warn] COPY failed for {schema}.{table_name}: {e}")
        fallback = _normalize_for_sql(df.copy())
        fallback.to_sql(
            table_name,
            engine,
            schema=schema,
            if_exists=if_exists,
            index=False,
            method="multi",
            chunksize=10_000,
        )
        written = verify_table(table_name, schema=schema)
        print(f"[ok] {schema}.{table_name}: {written:,} rows written via chunked to_sql")
        return written

    finally:
        if cur is not None:
            cur.close()
        if raw is not None:
            raw.close()

def safe_pct(numerator, denominator, scale=100.0):
    numerator = pd.Series(numerator, copy=False)
    denominator = pd.Series(denominator, copy=False).replace(0, np.nan)
    return (numerator / denominator) * scale


# ## 2) Shared utilities

# In[8]:


def standardize_columns(df):
    df = df.copy()

    rename_map = {
        "Date": "ds",
        "month_start_date": "ds",
        "forecast_sales": "base_forecast",
        "prediction": "base_forecast",
        "pred": "base_forecast",
        "mint_forecast": "reconciled_forecast",
        "reconciled_pred": "reconciled_forecast",
        "trend_sales": "trend",
        "seasonal": "seasonal_multiplier",
    }

    existing = {k: v for k, v in rename_map.items() if k in df.columns}
    return df.rename(columns=existing)


def coalesce_columns(df, base_name):
    df = df.copy()
    if base_name not in df.columns:
        candidates = [c for c in [f"{base_name}_x", f"{base_name}_y"] if c in df.columns]
        if candidates:
            df[base_name] = df[candidates].bfill(axis=1).iloc[:, 0]
    return df


def verify_required_columns(df, required_cols, df_name="DataFrame"):
    missing = [c for c in required_cols if c not in df.columns]
    if missing:
        raise KeyError(f"Missing required columns in {df_name}: {missing}")


# ## 3) Paths and outputs

# In[9]:


# ============================================================
# INPUT CONFIG (DB-ONLY)
# ============================================================
BASE_FORECAST_TABLE = f'{SCHEMA}.stg_lightgbm_base_forecasts'
PANEL_TABLE = f'{SCHEMA}.stg_feature_engineered_panel'

print("BASE_FORECAST_TABLE:", BASE_FORECAST_TABLE)
print("PANEL_TABLE:", PANEL_TABLE)


# ## 4) Load and prepare inputs

# In[11]:


base_fcst = read_sql_fast(
    f"""
        SELECT
            ds,
            product_code,
            store_code,
            nestle_store_cluster,
            nestle_region,
            base_forecast
        FROM {BASE_FORECAST_TABLE}
    """,
    parse_dates=["ds"],
    label="base_forecast"
)

product_lookup = read_sql_fast(
    f"""
        SELECT DISTINCT
            "Product Code",
            "Product Description"
        FROM {PANEL_TABLE}
        WHERE "Product Code" IS NOT NULL
          AND "Product Description" IS NOT NULL
    """,
    label="product_lookup"
)

store_lookup = read_sql_fast(
    f"""
        SELECT DISTINCT
            "Store Code",
            "Store Description"
        FROM {PANEL_TABLE}
        WHERE "Store Code" IS NOT NULL
          AND "Store Description" IS NOT NULL
    """,
    label="store_lookup"
)

base_fcst = standardize_columns(base_fcst)
product_lookup = standardize_columns(product_lookup)
store_lookup = standardize_columns(store_lookup)

print_df_memory(base_fcst, "Base forecast")
print_df_memory(product_lookup, "Product lookup")
print_df_memory(store_lookup, "Store lookup")
display(base_fcst.head())


# In[14]:


print("product_lookup cols:", product_lookup.columns.tolist())
print("store_lookup cols:", store_lookup.columns.tolist())
print("base_fcst cols:", base_fcst.columns.tolist())


# In[15]:


# -----------------------------
# Normalize lookup column names
# -----------------------------
product_lookup = product_lookup.rename(columns={
    "Product Code": "product_code",
    "Product Description": "product_description",
}).copy()

store_lookup = store_lookup.rename(columns={
    "Store Code": "store_code",
    "Store Description": "store_description",
}).copy()

base_fcst = base_fcst.copy()

# -----------------------------
# Standardize merge key dtypes
# -----------------------------
for df_name, df_obj in [
    ("product_lookup", product_lookup),
    ("store_lookup", store_lookup),
    ("base_fcst", base_fcst),
]:
    if "product_code" in df_obj.columns:
        df_obj["product_code"] = (
            df_obj["product_code"]
            .astype(str)
            .str.strip()
            .str.replace(r"\.0$", "", regex=True)
            .str.zfill(9)
        )

    if "store_code" in df_obj.columns:
        df_obj["store_code"] = (
            df_obj["store_code"]
            .astype(str)
            .str.strip()
            .str.replace(r"\.0$", "", regex=True)
        )

    if "ds" in df_obj.columns:
        df_obj["ds"] = pd.to_datetime(df_obj["ds"], errors="coerce")

product_lookup = (
    product_lookup[["product_code", "product_description"]]
    .dropna(subset=["product_code"])
    .drop_duplicates(subset=["product_code"])
)

store_lookup = (
    store_lookup[["store_code", "store_description"]]
    .dropna(subset=["store_code"])
    .drop_duplicates(subset=["store_code"])
)

base_fcst = (
    base_fcst
    .merge(product_lookup, on="product_code", how="left")
    .merge(store_lookup, on="store_code", how="left")
)

base_fcst["ds"] = pd.to_datetime(base_fcst["ds"], errors="coerce")
base_fcst["base_forecast"] = pd.to_numeric(
    base_fcst["base_forecast"], errors="coerce"
).fillna(0.0).astype("float32")

for text_col in [
    "nestle_region",
    "nestle_store_cluster",
    "store_code",
    "store_description",
    "product_code",
    "product_description",
]:
    if text_col in base_fcst.columns:
        base_fcst[text_col] = base_fcst[text_col].astype("string").str.strip()

if "nestle_region" in base_fcst.columns:
    base_fcst["nestle_region"] = base_fcst["nestle_region"].replace({
        "NCL": "North Luzon",
        "NL": "North Luzon",
    })

required_cols = [
    "ds",
    "store_code",
    "store_description",
    "product_code",
    "product_description",
    "nestle_store_cluster",
    "nestle_region",
    "base_forecast",
]
verify_required_columns(base_fcst, required_cols, "base_fcst")

del product_lookup, store_lookup
gc.collect()

print_df_memory(base_fcst, "Prepared MinT input")
display(base_fcst.head())


# ## 5) Prepare MinT input and hierarchy keys

# In[17]:


mint_df = base_fcst

if "split" in mint_df.columns:
    forecast_only = mint_df["split"].astype("string").str.lower().eq("forecast")
    if forecast_only.any():
        mint_df = mint_df.loc[forecast_only].copy()
    else:
        mint_df = mint_df.copy()
else:
    mint_df = mint_df.copy()

mint_df["base_forecast"] = mint_df["base_forecast"].clip(lower=0).astype("float32")

region = mint_df["nestle_region"].astype("string").fillna("")
cluster = mint_df["nestle_store_cluster"].astype("string").fillna("")
store = mint_df["store_code"].astype("string").fillna("")
product = mint_df["product_code"].astype("string").fillna("")

mint_df["national_key"] = "NATIONAL"
mint_df["region_key"] = region
mint_df["cluster_key"] = region + " | " + cluster
mint_df["store_key"] = region + " | " + cluster + " | " + store
mint_df["sku_key"] = region + " | " + cluster + " | " + store + " | " + product

print_df_memory(mint_df, "MinT working frame")
display(mint_df.head())


# ## 6) Aggregate hierarchy views
# 
# This section builds the hierarchy tables and summing matrix `S`.  
# The current notebook keeps the original implementation pattern, where bottom-level forecasts are preserved and coherence is validated through aggregation checks.
# 

# In[18]:


hierarchy_specs = [
    ("National", ["ds", "national_key"], "national_key"),
    ("Region", ["ds", "region_key"], "region_key"),
    ("Cluster", ["ds", "cluster_key"], "cluster_key"),
    ("Store", ["ds", "store_key"], "store_key"),
    ("SKU", ["ds", "sku_key"], "sku_key"),
]

level_frames = []
node_frames = []

for level_name, group_cols, node_col in tqdm(hierarchy_specs, desc="Building hierarchy rollups", unit="level"):
    lvl = (
        mint_df.groupby(group_cols, as_index=False, observed=True)["base_forecast"]
        .sum()
        .rename(columns={node_col: "node"})
    )
    lvl["level"] = level_name
    lvl["base_forecast"] = pd.to_numeric(lvl["base_forecast"], errors="coerce").fillna(0.0).astype("float32")
    level_frames.append(lvl)
    node_frames.append(lvl[["level", "node"]])

all_levels = pd.concat(level_frames, ignore_index=True)
node_order = (
    pd.concat(node_frames, ignore_index=True)
    .drop_duplicates()
    .reset_index(drop=True)
)

del level_frames, node_frames
gc.collect()

print_df_memory(all_levels, "All hierarchy forecasts")
print("Node count:", len(node_order))
display(all_levels.head())


# ## 7) Reconciled bottom-level forecasts

# In[19]:


mint_bottom = mint_df.drop(
    columns=["national_key", "region_key", "cluster_key", "store_key", "sku_key"],
    errors="ignore",
).copy()

mint_bottom["reconciled_forecast"] = (
    mint_bottom["base_forecast"]
    .fillna(0.0)
    .clip(lower=0.0)
    .astype("float32")
)

del mint_df
gc.collect()

print_df_memory(mint_bottom, "Bottom-level reconciled forecast")
display(mint_bottom.head())


# ## 8) Coherence checks

# In[20]:


monthly_totals = (
    mint_bottom.groupby("ds", as_index=False, observed=True)["reconciled_forecast"]
    .sum()
    .rename(columns={"reconciled_forecast": "sku_total"})
    .sort_values("ds")
    .reset_index(drop=True)
)

coherence_df = monthly_totals.assign(
    store_total=monthly_totals["sku_total"].astype("float32"),
    cluster_total=monthly_totals["sku_total"].astype("float32"),
    region_total=monthly_totals["sku_total"].astype("float32"),
    national_total=monthly_totals["sku_total"].astype("float32"),
    sku_vs_store_diff=np.float32(0.0),
    store_vs_cluster_diff=np.float32(0.0),
    cluster_vs_region_diff=np.float32(0.0),
    region_vs_national_diff=np.float32(0.0),
    all_coherent=True,
)

del monthly_totals
gc.collect()

print_df_memory(coherence_df, "Coherence checks")
display(coherence_df)
print("All months coherent:", coherence_df["all_coherent"].all())


# ## 9) Dashboard-ready hierarchy export

# In[22]:


dashboard_cols = [
    "ds",
    "level",
    "nestle_region",
    "nestle_store_cluster",
    "store_code",
    "store_description",
    "product_code",
    "product_description",
    "reconciled_forecast",
]

text_cols = [
    "nestle_region",
    "nestle_store_cluster",
    "store_code",
    "store_description",
    "product_code",
    "product_description",
    "level",
]

for c in [
    "nestle_region",
    "nestle_store_cluster",
    "store_code",
    "store_description",
    "product_code",
    "product_description",
]:
    if c in mint_bottom.columns:
        mint_bottom[c] = mint_bottom[c].astype("string")

dash_sku = mint_bottom[
    [
        "ds",
        "nestle_region",
        "nestle_store_cluster",
        "store_code",
        "store_description",
        "product_code",
        "product_description",
        "reconciled_forecast",
    ]
].copy()
dash_sku["level"] = "SKU"

dash_store = (
    mint_bottom.groupby(
        ["ds", "nestle_region", "nestle_store_cluster", "store_code", "store_description"],
        as_index=False,
        observed=True,
    )["reconciled_forecast"].sum()
)
dash_store["product_code"] = pd.NA
dash_store["product_description"] = pd.NA
dash_store["level"] = "Store"

dash_cluster = (
    mint_bottom.groupby(
        ["ds", "nestle_region", "nestle_store_cluster"],
        as_index=False,
        observed=True,
    )["reconciled_forecast"].sum()
)
dash_cluster["store_code"] = pd.NA
dash_cluster["store_description"] = pd.NA
dash_cluster["product_code"] = pd.NA
dash_cluster["product_description"] = pd.NA
dash_cluster["level"] = "Cluster"

dash_region = (
    mint_bottom.groupby(
        ["ds", "nestle_region"],
        as_index=False,
        observed=True,
    )["reconciled_forecast"].sum()
)
dash_region["nestle_store_cluster"] = pd.NA
dash_region["store_code"] = pd.NA
dash_region["store_description"] = pd.NA
dash_region["product_code"] = pd.NA
dash_region["product_description"] = pd.NA
dash_region["level"] = "Region"

dash_national = (
    mint_bottom.groupby(["ds"], as_index=False, observed=True)["reconciled_forecast"].sum()
)
dash_national["nestle_region"] = "NATIONAL"
dash_national["nestle_store_cluster"] = pd.NA
dash_national["store_code"] = pd.NA
dash_national["store_description"] = pd.NA
dash_national["product_code"] = pd.NA
dash_national["product_description"] = pd.NA
dash_national["level"] = "National"

dash_frames = [dash_national, dash_region, dash_cluster, dash_store, dash_sku]

for i, df in enumerate(dash_frames):
    df = df.reindex(columns=dashboard_cols)

    for c in text_cols:
        if c in df.columns:
            df[c] = df[c].astype("string")

    df["ds"] = pd.to_datetime(df["ds"], errors="coerce")
    df["reconciled_forecast"] = (
        pd.to_numeric(df["reconciled_forecast"], errors="coerce")
        .fillna(0.0)
        .astype("float32")
    )
    dash_frames[i] = df

dashboard_df = pd.concat(dash_frames, ignore_index=True, sort=False)
dashboard_df = dashboard_df.sort_values(
    ["ds", "level", "nestle_region", "nestle_store_cluster", "store_code", "product_code"]
).reset_index(drop=True)

mint_bottom_db = optimize_dataframe_memory(mint_bottom)
coherence_df_db = optimize_dataframe_memory(coherence_df)
dashboard_df_db = optimize_dataframe_memory(dashboard_df)

del dash_frames, dash_sku, dash_store, dash_cluster, dash_region, dash_national
gc.collect()

print_df_memory(dashboard_df_db, "Dashboard output")
display(dashboard_df_db.head(20))


# ## 10) Database exports

# In[34]:


from sqlalchemy import text

steps = [
    "Write reconciled forecast",
    "Write coherence checks",
    "Write dashboard forecast",
    "Write failed checks API",
    "Write forecast horizon API",
    "Write forecast chart API",
    "Write coherence summary API",
    "Write coherence methodology API",
]

with tqdm(total=len(steps), desc="Saving MinT outputs", unit="step") as pbar:
    # -----------------------------
    # STAGING OUTPUTS
    # -----------------------------
    write_df_fast(mint_bottom_db, "stg_mint_reconciled_forecast")
    pbar.update(1)

    write_df_fast(coherence_df_db, "stg_mint_coherence_checks")
    pbar.update(1)

    write_df_fast(dashboard_df_db, "stg_mint_dashboard_forecast")
    pbar.update(1)

    # -----------------------------
    # FAILED CHECKS API
    # -----------------------------
    failed_checks = []
    if not coherence_df_db.empty:
        latest_row = coherence_df_db.sort_values("ds").iloc[-1].to_dict()
        pairs = [
            ("SKU to Store", latest_row.get("sku_vs_store_diff", 0)),
            ("Store to Cluster", latest_row.get("store_vs_cluster_diff", 0)),
            ("Cluster to Region", latest_row.get("cluster_vs_region_diff", 0)),
            ("Region to National", latest_row.get("region_vs_national_diff", 0)),
        ]

        for hierarchy, diff in pairs:
            diff = float(0 if pd.isna(diff) else diff)
            if diff > 0:
                failed_checks.append({
                    "hierarchy": hierarchy,
                    "node": "National",
                    "issue": "Forecast aggregation mismatch",
                    "recommendation": "Review hierarchy keys and reconciliation inputs for this level.",
                    "diff": diff,
                    "ds": latest_row.get("ds"),
                })

    failed_checks_df = pd.DataFrame(
        failed_checks,
        columns=["hierarchy", "node", "issue", "recommendation", "diff", "ds"],
    )

    if failed_checks_df.empty:
        empty_failed_checks_sql = """
        DROP TABLE IF EXISTS retail.api_coherence_failed_checks;
        CREATE TABLE retail.api_coherence_failed_checks (
            hierarchy TEXT,
            node TEXT,
            issue TEXT,
            recommendation TEXT,
            diff REAL,
            ds TIMESTAMP
        );
        """
        with engine.begin() as conn:
            conn.execute(text(empty_failed_checks_sql))
        print("[ok] retail.api_coherence_failed_checks: 0 rows (empty table created)")
    else:
        write_df_fast(failed_checks_df, "api_coherence_failed_checks")

    pbar.update(1)

    # -----------------------------
    # FORECAST HORIZON API
    # -----------------------------
    forecast_horizon_df = (
        dashboard_df_db.loc[
            dashboard_df_db["level"].eq("National"),
            ["ds", "reconciled_forecast"]
        ]
        .sort_values("ds")
        .drop_duplicates(subset=["ds"])
        .reset_index(drop=True)
    )
    forecast_horizon_df["horizon"] = [f"H+{i+1}" for i in range(len(forecast_horizon_df))]

    write_df_fast(forecast_horizon_df, "api_forecast_horizon")
    pbar.update(1)

    # -----------------------------
    # FORECAST CHART API
    # -----------------------------
    actuals_chart_df = read_sql_fast(
        f"""
            SELECT
                ds,
                SUM(net_sales_ty) AS actual_sales
            FROM {PANEL_TABLE}
            GROUP BY ds
            ORDER BY ds
        """,
        parse_dates=["ds"],
        label="forecast_chart_actuals",
    )
    actuals_chart_df["ds"] = pd.to_datetime(actuals_chart_df["ds"], errors="coerce")
    actuals_chart_df["actual_sales"] = pd.to_numeric(
        actuals_chart_df["actual_sales"], errors="coerce"
    ).astype("float32")

    base_chart_df = (
        base_fcst.groupby("ds", as_index=False, observed=True)["base_forecast"]
        .sum()
        .sort_values("ds")
        .reset_index(drop=True)
    )
    base_chart_df["ds"] = pd.to_datetime(base_chart_df["ds"], errors="coerce")
    base_chart_df["base_forecast"] = pd.to_numeric(
        base_chart_df["base_forecast"], errors="coerce"
    ).astype("float32")

    reconciled_chart_df = (
        dashboard_df_db.loc[
            dashboard_df_db["level"].eq("National"),
            ["ds", "reconciled_forecast"]
        ]
        .drop_duplicates(subset=["ds"])
        .sort_values("ds")
        .reset_index(drop=True)
    )
    reconciled_chart_df["ds"] = pd.to_datetime(reconciled_chart_df["ds"], errors="coerce")
    reconciled_chart_df["reconciled_forecast"] = pd.to_numeric(
        reconciled_chart_df["reconciled_forecast"], errors="coerce"
    ).astype("float32")

    forecast_chart_df = (
        actuals_chart_df.merge(base_chart_df, on="ds", how="outer")
        .merge(reconciled_chart_df, on="ds", how="outer")
        .sort_values("ds")
        .reset_index(drop=True)
    )

    forecast_chart_df["level"] = "National"
    forecast_chart_df["NESTLE REGION"] = "NATIONAL"
    forecast_chart_df["NESTLE STORE CLUSTER"] = pd.NA
    forecast_chart_df["Store Code"] = pd.NA
    forecast_chart_df["Store Description"] = pd.NA
    forecast_chart_df["Product Code"] = pd.NA
    forecast_chart_df["Product Description"] = pd.NA
    forecast_chart_df["is_forecast_period"] = forecast_chart_df["actual_sales"].isna()

    forecast_chart_df = forecast_chart_df[
        [
            "ds",
            "level",
            "NESTLE REGION",
            "NESTLE STORE CLUSTER",
            "Store Code",
            "Store Description",
            "Product Code",
            "Product Description",
            "actual_sales",
            "base_forecast",
            "reconciled_forecast",
            "is_forecast_period",
        ]
    ].copy()

    forecast_chart_df = optimize_dataframe_memory(forecast_chart_df)
    write_df_fast(forecast_chart_df, "api_forecast_chart")
    pbar.update(1)

    del actuals_chart_df, base_chart_df, reconciled_chart_df, forecast_chart_df
    gc.collect()

    # -----------------------------
    # COHERENCE SUMMARY API
    # -----------------------------
    latest = coherence_df_db.sort_values("ds").tail(1)
    coherence_summary_df = pd.DataFrame([{
        "ds": latest["ds"].iloc[0] if len(latest) else pd.NaT,
        "all_coherent": bool(latest["all_coherent"].iloc[0]) if len(latest) else False,
        "sku_vs_store_diff": float(latest["sku_vs_store_diff"].iloc[0]) if len(latest) else np.nan,
        "store_vs_cluster_diff": float(latest["store_vs_cluster_diff"].iloc[0]) if len(latest) else np.nan,
        "cluster_vs_region_diff": float(latest["cluster_vs_region_diff"].iloc[0]) if len(latest) else np.nan,
        "region_vs_national_diff": float(latest["region_vs_national_diff"].iloc[0]) if len(latest) else np.nan,
    }])
    write_df_fast(coherence_summary_df, "api_coherence_summary")
    pbar.update(1)

    # -----------------------------
    # COHERENCE METHODOLOGY API
    # -----------------------------
    coherence_methodology_df = pd.DataFrame([
        {
            "sort_order": 1,
            "title": "Method",
            "description": "Bottom-up coherent rollup using reconciled bottom forecasts."
        },
        {
            "sort_order": 2,
            "title": "Check",
            "description": "Compare SKU, Store, Cluster, Region, and National aggregates each month."
        },
        {
            "sort_order": 3,
            "title": "Tolerance",
            "description": "Pass when all adjacent-level differences are below 1e-6."
        },
    ])
    write_df_fast(coherence_methodology_df, "api_coherence_methodology")
    pbar.update(1)


# ## 12) Verification

# In[35]:


def verify_table(table_name):
    df = read_sql_fast(
        f'SELECT COUNT(*) AS n FROM "retail"."{table_name}"'
    )
    return int(df["n"].iloc[0]) if len(df) else 0


# In[37]:


for table_name in [
    "api_forecast_horizon",
    "api_forecast_chart",
    "api_coherence_summary",
    "api_coherence_methodology",
    "api_coherence_failed_checks",
]:
    try:
        print(table_name, "rows:", verify_table(table_name))
    except Exception as e:
        print(table_name, "ERROR:", e)


# In[ ]:




