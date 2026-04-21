#!/usr/bin/env python
# coding: utf-8

# # Feature Engineering Notebook
# 

# In[1]:


import pandas as pd
import numpy as np
from pathlib import Path
import json
from io import StringIO
import csv
import math

pd.set_option("display.max_columns", 200)
pd.set_option("display.width", 200)

PROJECT_NAME = "nestle"
FEATURE_STORE_DIR = Path(r"D:\JN\Thesis\Dataset\curated\splits")
FEATURE_STORE_DIR.mkdir(parents=True, exist_ok=True)

OUT_PANEL = FEATURE_STORE_DIR / "nestle_layered_panel_with_splits.parquet"
OUT_META = FEATURE_STORE_DIR / "nestle_split_meta.json"

print("Output panel:", OUT_PANEL)
print("Output metadata:", OUT_META)


# ## 1. Setup and database helpers
# 

# In[2]:


# ============================================================
# DB HELPERS (STREAM-FRIENDLY READ/WRITE)
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
READ_CHUNKSIZE = 25000
WRITE_CHUNKSIZE = 25000

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

    out = df

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
        elif isinstance(out[c].dtype, pd.CategoricalDtype):
            out[c] = out[c].astype("string")
    out = out.replace({pd.NA: None})
    return out

def verify_table(table_name, schema=SCHEMA):
    q = text(f'SELECT COUNT(*) AS n FROM "{schema}"."{table_name}"')
    with engine.begin() as conn:
        return int(pd.read_sql(q, conn).iloc[0, 0])

def stream_sql_chunks(
    query,
    parse_dates=None,
    chunksize=READ_CHUNKSIZE,
    label="query",
    optimize_memory=True,
):
    sql = text(query) if isinstance(query, str) else query
    iterator = pd.read_sql_query(
        sql,
        engine,
        parse_dates=parse_dates,
        chunksize=chunksize,
    )
    for chunk in tqdm(iterator, desc=f"Loading {label}", unit="chunk"):
        chunk = standardize_columns(chunk)
        if optimize_memory:
            chunk = optimize_dataframe_memory(chunk)
        yield chunk

def read_sql_fast(
    query,
    parse_dates=None,
    chunksize=READ_CHUNKSIZE,
    label="query",
    optimize_memory=True,
):
    frames = []
    for chunk in stream_sql_chunks(
        query=query,
        parse_dates=parse_dates,
        chunksize=chunksize,
        label=label,
        optimize_memory=optimize_memory,
    ):
        frames.append(chunk)

    if not frames:
        return pd.DataFrame()

    df = pd.concat(frames, ignore_index=True)
    del frames
    gc.collect()
    return optimize_dataframe_memory(df) if optimize_memory else df

def read_sql_one(query, parse_dates=None, label="query", optimize_memory=True):
    sql = text(query) if isinstance(query, str) else query
    df = pd.read_sql_query(sql, engine, parse_dates=parse_dates)
    df = standardize_columns(df)
    if optimize_memory:
        df = optimize_dataframe_memory(df)
    print(f"[ok] Loaded {label}: {df.shape}")
    return df

def write_df_fast(df, table_name, schema=SCHEMA, if_exists="replace", chunk_size=WRITE_CHUNKSIZE):
    """Memory-aware PostgreSQL write using per-chunk normalization + COPY."""
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

        first_chunk = _normalize_for_sql(df.iloc[:min(chunk_size, total_rows)].copy())
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
        for start in tqdm(range(0, total_rows, chunk_size), desc=f"Fallback write {table_name}", unit="chunk"):
            chunk = _normalize_for_sql(df.iloc[start:start + chunk_size].copy())
            chunk.to_sql(
                table_name,
                engine,
                schema=schema,
                if_exists=if_exists if start == 0 else "append",
                index=False,
                method="multi",
                chunksize=min(10000, chunk_size),
            )
            del chunk
        written = verify_table(table_name, schema=schema)
        print(f"[ok] {schema}.{table_name}: {written:,} rows written via chunked to_sql")
        return written

    finally:
        if cur is not None:
            cur.close()
        if raw is not None:
            raw.close()
        gc.collect()

def safe_pct(numerator, denominator, scale=100.0):
    numerator = pd.Series(numerator, copy=False)
    denominator = pd.Series(denominator, copy=False).replace(0, np.nan)
    return (numerator / denominator) * scale


# ## 2. Reusable helper functions
# 

# In[3]:


def normalize_keys(df):

    df = df.copy()

    if "Product Code" in df.columns:
        df["Product Code"] = df["Product Code"].astype(str).str.strip()

    if "Store Code" in df.columns:
        df["Store Code"] = df["Store Code"].astype(str).str.strip()

    if "ds" in df.columns:
        df["ds"] = pd.to_datetime(df["ds"]).dt.to_period("M").dt.to_timestamp()

    return df


# In[4]:


def clean_descriptions(df):

    df = df.copy()

    for col in ["Product Description", "Store Description"]:
        if col in df.columns:
            df[col] = (
                df[col]
                .astype(str)
                .str.replace("\n", " ")
                .str.strip()
            )

    return df


# In[5]:


def coerce_numeric_columns(df):

    df = df.copy()

    numeric_cols = [
        "Units Sold TY",
        "Units Sold LY",
        "Net Sales TY",
        "Net Sales LY",
    ]

    for col in numeric_cols:
        if col in df.columns:
            df[col] = pd.to_numeric(df[col], errors="coerce")

    return df


# In[6]:


def extract_prior_year_from_ly_auto(df):

    df = df.copy()
    df["ds"] = pd.to_datetime(df["ds"]).dt.to_period("M").dt.to_timestamp()

    required_cols = ["ds","Product Code","Store Code","Units Sold LY","Net Sales LY"]

    missing = [c for c in required_cols if c not in df.columns]

    if missing:
        print("LY extraction skipped. Missing:", missing)
        return df

    earliest_year = df["ds"].dt.year.min()

    earliest_df = df[df["ds"].dt.year == earliest_year].copy()

    ly_mask = (
        earliest_df["Units Sold LY"].notna() |
        earliest_df["Net Sales LY"].notna()
    )

    earliest_df = earliest_df.loc[ly_mask]

    if earliest_df.empty:
        return df

    prior_rows = earliest_df.copy()

    prior_rows["ds"] = prior_rows["ds"] - pd.DateOffset(years=1)

    prior_rows["Units Sold TY"] = prior_rows["Units Sold LY"]
    prior_rows["Net Sales TY"] = prior_rows["Net Sales LY"]

    prior_rows["Units Sold LY"] = np.nan
    prior_rows["Net Sales LY"] = np.nan

    df = pd.concat([df, prior_rows], ignore_index=True)

    return df


# ## 3. Load cleaned source data
# 

# In[7]:


# ============================================================
# LOAD REDUCED MONTHLY PANEL + LOOKUPS FROM DATABASE
# ============================================================
raw_summary = read_sql_one(
    """
    SELECT
        COUNT(*) AS raw_rows,
        COUNT(DISTINCT "Product Code") AS raw_unique_skus,
        COUNT(DISTINCT "Store Code") AS raw_unique_stores,
        MIN(ds)::date AS raw_min_ds,
        MAX(ds)::date AS raw_max_ds
    FROM retail.stg_cleaned_sales_panel
    """,
    parse_dates=["raw_min_ds", "raw_max_ds"],
    label="raw panel summary",
    optimize_memory=False,
).iloc[0].to_dict()

panel = read_sql_fast(
    """
    WITH base AS (
        SELECT
            DATE_TRUNC('month', ds)::date AS ds,
            TRIM(COALESCE("Product Code"::text, '')) AS "Product Code",
            TRIM(COALESCE("Store Code"::text, '')) AS "Store Code",
            NULLIF(TRIM(COALESCE("Brand"::text, '')), '') AS "Brand",
            NULLIF(TRIM(COALESCE("NESTLE STORE CLUSTER"::text, '')), '') AS "NESTLE STORE CLUSTER",
            NULLIF(TRIM(COALESCE("NESTLE REGION"::text, '')), '') AS "NESTLE REGION",
            "Units Sold TY",
            "Units Sold LY",
            "Net Sales TY",
            "Net Sales LY"
        FROM retail.stg_cleaned_sales_panel
        WHERE ds IS NOT NULL
          AND COALESCE(TRIM("Product Code"::text), '') <> ''
          AND COALESCE(TRIM("Store Code"::text), '') <> ''
    ),
    current_rows AS (
        SELECT
            ds,
            "Product Code",
            "Store Code",
            "Brand",
            "NESTLE STORE CLUSTER",
            "NESTLE REGION",
            SUM(COALESCE("Units Sold TY", 0)) AS units_sold_ty,
            SUM(COALESCE("Net Sales TY", 0)) AS net_sales_ty
        FROM base
        GROUP BY 1,2,3,4,5,6
    ),
    earliest_year AS (
        SELECT MIN(EXTRACT(YEAR FROM ds))::int AS yr
        FROM base
    ),
    prior_rows AS (
        SELECT
            (ds - INTERVAL '1 year')::date AS ds,
            "Product Code",
            "Store Code",
            "Brand",
            "NESTLE STORE CLUSTER",
            "NESTLE REGION",
            SUM(COALESCE("Units Sold LY", 0)) AS units_sold_ty,
            SUM(COALESCE("Net Sales LY", 0)) AS net_sales_ty
        FROM base
        WHERE EXTRACT(YEAR FROM ds)::int = (SELECT yr FROM earliest_year)
          AND ("Units Sold LY" IS NOT NULL OR "Net Sales LY" IS NOT NULL)
        GROUP BY 1,2,3,4,5,6
    )
    SELECT
        ds,
        "Product Code",
        "Store Code",
        "Brand",
        "NESTLE STORE CLUSTER",
        "NESTLE REGION",
        SUM(units_sold_ty) AS units_sold_ty,
        SUM(net_sales_ty) AS net_sales_ty
    FROM (
        SELECT * FROM current_rows
        UNION ALL
        SELECT * FROM prior_rows
    ) src
    GROUP BY 1,2,3,4,5,6
    ORDER BY "Product Code", "Store Code", ds
    """,
    parse_dates=["ds"],
    chunksize=READ_CHUNKSIZE,
    label="monthly feature panel",
)

product_desc_map = (
    read_sql_fast(
        """
        SELECT DISTINCT ON ("Product Code")
            TRIM("Product Code"::text) AS "Product Code",
            NULLIF(TRIM("Product Description"::text), '') AS "Product Description"
        FROM retail.stg_cleaned_sales_panel
        WHERE COALESCE(TRIM("Product Code"::text), '') <> ''
        ORDER BY "Product Code", "Product Description" NULLS LAST
        """,
        label="product descriptions",
        chunksize=READ_CHUNKSIZE,
    )
    .dropna(subset=["Product Code"])
    .drop_duplicates(subset=["Product Code"], keep="first")
    .set_index("Product Code")["Product Description"]
)

store_desc_map = (
    read_sql_fast(
        """
        SELECT DISTINCT ON ("Store Code")
            TRIM("Store Code"::text) AS "Store Code",
            NULLIF(TRIM("Store Description"::text), '') AS "Store Description"
        FROM retail.stg_cleaned_sales_panel
        WHERE COALESCE(TRIM("Store Code"::text), '') <> ''
        ORDER BY "Store Code", "Store Description" NULLS LAST
        """,
        label="store descriptions",
        chunksize=READ_CHUNKSIZE,
    )
    .dropna(subset=["Store Code"])
    .drop_duplicates(subset=["Store Code"], keep="first")
    .set_index("Store Code")["Store Description"]
)

panel["ds"] = pd.to_datetime(panel["ds"], errors="coerce")
panel["units_sold_ty"] = pd.to_numeric(panel["units_sold_ty"], errors="coerce")
panel["net_sales_ty"] = pd.to_numeric(panel["net_sales_ty"], errors="coerce")

print("Loaded monthly panel shape:", panel.shape)
print("Loaded monthly panel memory MB:", round(panel.memory_usage(deep=True).sum() / 1024**2, 2))


# ## 4. Raw preprocessing
# 

# In[8]:


panel = standardize_columns(panel)
panel["Product Code"] = panel["Product Code"].astype(str).str.strip()
panel["Store Code"] = panel["Store Code"].astype(str).str.strip()
panel["ds"] = pd.to_datetime(panel["ds"], errors="coerce").dt.to_period("M").dt.to_timestamp()

for col in ["units_sold_ty", "net_sales_ty"]:
    if col in panel.columns:
        panel[col] = pd.to_numeric(panel[col], errors="coerce").fillna(0.0)

panel = optimize_dataframe_memory(panel)

print("Rows after SQL-side reduction:", len(panel))
print("Date range:", panel["ds"].min(), "to", panel["ds"].max())


# ## 5. Build dense panel
# 

# In[9]:


panel = panel.sort_values(
    ["Product Code", "Store Code", "NESTLE STORE CLUSTER", "NESTLE REGION", "ds"]
).reset_index(drop=True)


# In[10]:


key_cols = ["Product Code", "Store Code", "NESTLE STORE CLUSTER", "NESTLE REGION"]

panel["is_observed_month"] = 1

def densify_within_active_span(g):
    g = g.sort_values("ds").copy()
    full_ds = pd.date_range(g["ds"].min(), g["ds"].max(), freq="MS")

    base = g[key_cols].iloc[0].to_dict()

    out = (
        g.set_index("ds")
         .reindex(full_ds)
         .rename_axis("ds")
         .reset_index()
    )

    for c, v in base.items():
        out[c] = v

    out["is_observed_month"] = out["is_observed_month"].fillna(0).astype("int8")
    out["is_gap_filled"] = (out["is_observed_month"] == 0).astype("int8")

    out["net_sales_ty"] = pd.to_numeric(out["net_sales_ty"], errors="coerce").fillna(0.0)
    out["units_sold_ty"] = pd.to_numeric(out["units_sold_ty"], errors="coerce").fillna(0.0)
    return out

panel = (
    panel.groupby(key_cols, group_keys=False)
    .apply(densify_within_active_span)
    .reset_index(drop=True)
)

panel = panel.sort_values(["Product Code", "Store Code", "ds"]).reset_index(drop=True)

print("Dense panel rows:", len(panel))
print("Gap-filled share:", f'{panel["is_gap_filled"].mean():.2%}')


# ## 6. Attach product and store descriptions
# 

# In[11]:


# ============================================================
# ATTACH PRODUCT / STORE DESCRIPTIONS TO PANEL
# ============================================================
panel["Product Description"] = panel["Product Code"].map(product_desc_map)
panel["Store Description"] = panel["Store Code"].map(store_desc_map)

desc_fill = {
    "Product Description": "Unknown Product",
    "Store Description": "Unknown Store",
}
for col, default_val in desc_fill.items():
    if col in panel.columns:
        panel[col] = panel[col].fillna(default_val).astype("string")

display(
    panel[["Product Code", "Product Description", "Store Code", "Store Description"]]
    .drop_duplicates()
    .head(10)
)

gc.collect()


# ## 7. Calendar, lag, and rolling features
# 

# In[12]:


panel["month"] = panel["ds"].dt.month
panel["quarter"] = panel["ds"].dt.quarter
panel["year"] = panel["ds"].dt.year

panel["month_sin"] = np.sin(2*np.pi*panel["month"]/12)
panel["month_cos"] = np.cos(2*np.pi*panel["month"]/12)


# In[13]:


lag_list = [1, 2, 3, 6, 12]
group_keys = ["Product Code", "Store Code"]

panel = panel.sort_values(group_keys + ["ds"]).reset_index(drop=True)
grouped_sales = panel.groupby(group_keys, sort=False)["net_sales_ty"]

for lag in lag_list:
    panel[f"lag_{lag}"] = grouped_sales.shift(lag).astype("float32")


# In[14]:


group_key_arrays = [panel["Product Code"], panel["Store Code"]]
shifted_sales = grouped_sales.shift(1).astype("float32")

rolling_min_periods = {3: 2, 6: 3, 12: 6}

for w in [3, 6, 12]:
    minp = rolling_min_periods[w]

    panel[f"rolling_mean_{w}"] = (
        shifted_sales
        .groupby(group_key_arrays, sort=False)
        .rolling(window=w, min_periods=minp)
        .mean()
        .reset_index(level=[0, 1], drop=True)
        .astype("float32")
    )

    panel[f"rolling_std_{w}"] = (
        shifted_sales
        .groupby(group_key_arrays, sort=False)
        .rolling(window=w, min_periods=minp)
        .std()
        .reset_index(level=[0, 1], drop=True)
        .fillna(0.0)
        .astype("float32")
    )

panel["trend_growth"] = (
    (panel["lag_1"] - panel["lag_2"]) /
    np.maximum(np.abs(panel["lag_2"]), 1.0)
)
panel["trend_growth"] = (
    panel["trend_growth"]
    .replace([np.inf, -np.inf], 0.0)
    .fillna(0.0)
    .astype("float32")
)

panel["rolling_growth_3"] = (
    (panel["rolling_mean_3"] - panel["rolling_mean_6"]) /
    np.maximum(np.abs(panel["rolling_mean_6"]), 1.0)
)
panel["rolling_growth_3"] = (
    panel["rolling_growth_3"]
    .replace([np.inf, -np.inf], 0.0)
    .fillna(0.0)
    .astype("float32")
)

print("Rolling feature coverage")
display(
    pd.DataFrame({
        "feature": [f"rolling_mean_{w}" for w in [3, 6, 12]] + [f"rolling_std_{w}" for w in [3, 6, 12]],
        "missing_share": [
            panel[f"rolling_mean_{w}"].isna().mean() for w in [3, 6, 12]
        ] + [
            panel[f"rolling_std_{w}"].isna().mean() for w in [3, 6, 12]
        ]
    })
)


# ## 8. Split assignment and eligibility features
# 

# In[15]:


TRAIN_END = pd.Timestamp("2025-05-01")
VAL_END = pd.Timestamp("2025-07-01")

panel["split"] = np.select(
    [
        panel["ds"] <= TRAIN_END,
        (panel["ds"] > TRAIN_END) & (panel["ds"] <= VAL_END),
        panel["ds"] > VAL_END,
    ],
    ["train","val","test"],
    default="unknown"
)

print(panel["split"].value_counts())


# In[16]:


series_key_cols = ["Product Code", "Store Code", "NESTLE STORE CLUSTER", "NESTLE REGION"]

train_mask = panel["split"] == "train"
train_series = panel.loc[
    train_mask,
    series_key_cols + ["ds", "net_sales_ty", "is_gap_filled"]
].copy()

train_series["nonzero_flag"] = train_series["net_sales_ty"].gt(0).astype("int8")

series_train_summary = (
    train_series
    .groupby(series_key_cols, as_index=False, observed=True)
    .agg(
        history_months_train=("ds", "count"),
        nonzero_months_train=("nonzero_flag", "sum"),
        demand_share_train=("nonzero_flag", "mean"),
        total_sales_train=("net_sales_ty", "sum"),
        filled_gap_share_train=("is_gap_filled", "mean"),
    )
)

strong_mask = (
    (series_train_summary["history_months_train"] >= 12) &
    (series_train_summary["nonzero_months_train"] >= 12) &
    (series_train_summary["demand_share_train"] >= 0.30) &
    (series_train_summary["total_sales_train"] > 0)
)

medium_mask = (
    (series_train_summary["history_months_train"] >= 6) &
    (series_train_summary["nonzero_months_train"] >= 4) &
    (series_train_summary["demand_share_train"] >= 0.10) &
    (series_train_summary["total_sales_train"] > 0)
)

series_train_summary["eligibility_tier"] = np.select(
    [strong_mask, medium_mask],
    ["strong", "medium"],
    default="weak"
)

series_train_summary["eligible_for_model"] = (
    series_train_summary["eligibility_tier"].isin(["strong", "medium"])
).astype("int8")

series_train_summary["eligible_for_strict_eval"] = strong_mask.astype("int8")

panel = panel.merge(
    series_train_summary,
    on=series_key_cols,
    how="left",
    validate="many_to_one"
)

fill_defaults = {
    "history_months_train": 0,
    "nonzero_months_train": 0,
    "demand_share_train": 0.0,
    "total_sales_train": 0.0,
    "filled_gap_share_train": 0.0,
    "eligible_for_model": 0,
    "eligible_for_strict_eval": 0,
    "eligibility_tier": "weak"
}

for c, default_val in fill_defaults.items():
    if c in panel.columns:
        panel[c] = panel[c].fillna(default_val)

panel["history_months_train"] = panel["history_months_train"].astype("int16")
panel["nonzero_months_train"] = panel["nonzero_months_train"].astype("int16")
panel["eligible_for_model"] = panel["eligible_for_model"].astype("int8")
panel["eligible_for_strict_eval"] = panel["eligible_for_strict_eval"].astype("int8")
panel["eligibility_tier"] = panel["eligibility_tier"].astype("string")

print("Eligible series share:", f'{panel["eligible_for_model"].mean():.2%}')
display(
    series_train_summary["eligibility_tier"]
    .value_counts(dropna=False)
    .rename_axis("eligibility_tier")
    .to_frame("series_count")
)
display(
    series_train_summary[[
        "history_months_train",
        "nonzero_months_train",
        "demand_share_train",
        "filled_gap_share_train"
    ]].describe().T
)


# ## 9. Required output checks
# 

# In[17]:


required_features = [
    "lag_1", "lag_2", "lag_3", "lag_6", "lag_12",
    "rolling_mean_3", "rolling_mean_6", "rolling_mean_12",
    "rolling_std_3", "rolling_std_6", "rolling_std_12",
    "trend_growth", "rolling_growth_3",
    "is_observed_month", "is_gap_filled",
    "history_months_train", "nonzero_months_train",
    "demand_share_train", "total_sales_train",
    "filled_gap_share_train", "eligible_for_model",
    "eligible_for_strict_eval", "eligibility_tier"
]

required_id_cols = [
    "ds", "Product Code", "Store Code",
    "NESTLE STORE CLUSTER", "NESTLE REGION"
]

missing_features = [c for c in required_features if c not in panel.columns]
missing_id_cols = [c for c in required_id_cols if c not in panel.columns]

if missing_features or missing_id_cols:
    raise KeyError(
        f"Missing required columns before export. "
        f"Features: {missing_features} | ID cols: {missing_id_cols}"
    )

print("Required feature and ID checks passed.")


# ## 10. Final export panel preparation
# 

# In[18]:


# ============================================================
# STANDARDIZE + MOVE BRAND VALUES INTO CATEGORY
# ============================================================
if "Brand" in panel.columns:
    panel["Brand"] = panel["Brand"].astype("string").str.strip()

    panel["Category"] = panel["Brand"].replace({
        "Nestle": "Nestlé",
        "NESTLE": "Nestlé",
        "nestle": "Nestlé",
        "Wyeth": "Wyeth",
        "WYETH": "Wyeth",
        "wyeth": "Wyeth"
    })

    panel["Brand"] = pd.NA

panel = panel.sort_values(["Product Code", "Store Code", "ds"]).reset_index(drop=True)
panel_export = panel.copy()

print(panel_export[["Product Code", "Brand", "Category"]].head())
print(panel_export["Category"].value_counts(dropna=False))


# ## 11. Parquet and metadata export
# 

# In[19]:


# ============================================================
# OPTIONAL LOCAL PARQUET EXPORT DISABLED
# DB is the source of truth for downstream notebooks.
# ============================================================
print("Skipped local parquet export. Downstream notebooks now read from retail.stg_feature_engineered_panel.")


# In[20]:


train_ds = panel_export.loc[panel_export["split"]=="train","ds"]
val_ds = panel_export.loc[panel_export["split"]=="val","ds"]
test_ds = panel_export.loc[panel_export["split"]=="test","ds"]

meta = {
"train_start": str(train_ds.min().date()),
"train_end": str(train_ds.max().date()),
"valid_start": str(val_ds.min().date()),
"valid_end": str(val_ds.max().date()),
"test_start": str(test_ds.min().date()),
"test_end": str(test_ds.max().date()),
"grain": "Product Code × Store Code × Month",
"pipeline_stage": "feature_engineering",
"handoff_mode": "database_only"
}

meta_df = pd.DataFrame([meta])
write_df_fast(meta_df, "stg_feature_engineered_panel_meta", chunk_size=1000)
print("Saved feature engineering metadata to retail.stg_feature_engineered_panel_meta")


# ## 12. Final QA summary
# 

# In[21]:


# ============================================================
# FINAL QA SUMMARY
# ============================================================
print("RAW rows:", int(raw_summary["raw_rows"]))
print("RAW unique SKUs:", int(raw_summary["raw_unique_skus"]))
print("RAW unique Stores:", int(raw_summary["raw_unique_stores"]))
print("RAW date range:", raw_summary["raw_min_ds"], "to", raw_summary["raw_max_ds"])

print("\nDense / final panel shape:", panel_export.shape)
print("Dense / final unique SKUs:", panel_export["Product Code"].nunique())
print("Dense / final unique Stores:", panel_export["Store Code"].nunique())
print("Dense / final date range:", panel_export["ds"].min(), "to", panel_export["ds"].max())

print("\nSplit counts:")
print(panel_export["split"].value_counts(dropna=False).sort_index())

print("\nCategory distribution:")
print(panel_export["Category"].value_counts(dropna=False))

print("\nFinal columns:")
print(panel_export.columns.tolist())

display(panel_export.head())

sample_product = panel_export["Product Code"].iloc[0]
display(
    panel_export.loc[panel_export["Product Code"] == sample_product, [
        "Product Code",
        "Store Code",
        "ds",
        "net_sales_ty",
        "lag_1",
        "lag_2",
        "lag_3",
        "lag_6",
        "lag_12",
        "rolling_mean_3",
        "rolling_mean_6",
        "history_months_train",
        "eligible_for_model",
        "eligibility_tier"
    ]].head(20)
)


# ## 13. MSTL input panel export
# 

# In[22]:


# ============================================================
# DERIVE MSTL INPUT PANEL FROM THE OFFICIAL LAYERED PANEL
# ============================================================

# Assumes your official FE panel is already loaded in `panel`
# and contains at least:
# - Product Code
# - ds
# - net_sales_ty

panel["Product Code"] = panel["Product Code"].astype(str).str.zfill(9)
panel["ds"] = pd.to_datetime(panel["ds"])

panel_mstl = (
    panel.groupby(["Product Code", "ds"], as_index=False)["net_sales_ty"]
         .sum()
         .rename(columns={"net_sales_ty": "y"})
         .sort_values(["Product Code", "ds"])
         .reset_index(drop=True)
)

print("MSTL panel shape:", panel_mstl.shape)
print("Unique products:", panel_mstl["Product Code"].nunique())
print("Duplicate Product Code + ds rows:",
      panel_mstl.duplicated(["Product Code", "ds"]).sum())

display(panel_mstl.head())


# In[23]:


# ============================================================
# OPTIONAL LOCAL MSTL PARQUET EXPORT DISABLED
# MSTL now reads from retail.stg_mstl_product_month.
# ============================================================
print("Skipped local MSTL parquet export. DB table retail.stg_mstl_product_month is the official MSTL input.")


# ## 14. Pre-DB export validation
# 

# In[24]:


print("panel_export shape:", panel_export.shape)
print("panel_mstl shape:", panel_mstl.shape)
print("panel_export memory MB:", panel_export.memory_usage(deep=True).sum() / 1024**2)
print("panel_mstl memory MB:", panel_mstl.memory_usage(deep=True).sum() / 1024**2)


# In[25]:


# ============================================================
# PRE-DB EXPORT VALIDATION
# ============================================================
panel = standardize_columns(panel)

required_cols = [
    "ds", "Product Code", "Store Code",
    "NESTLE STORE CLUSTER", "NESTLE REGION"
]

missing = [c for c in required_cols if c not in panel.columns]
if missing:
    raise ValueError(f"Missing required columns: {missing}")


# ## 15. Database export and verification
# 

# In[26]:


# ============================================================
# DB EXPORTS: FEATURE STORE
# ============================================================
panel_export = standardize_columns(panel_export.copy())
panel_mstl = standardize_columns(panel_mstl.copy())

if "ds" in panel_export.columns:
    panel_export["ds"] = pd.to_datetime(panel_export["ds"], errors="coerce")
if "ds" in panel_mstl.columns:
    panel_mstl["ds"] = pd.to_datetime(panel_mstl["ds"], errors="coerce")

for col in ["net_sales_ty", "units_sold_ty"]:
    if col in panel_export.columns:
        panel_export[col] = pd.to_numeric(panel_export[col], errors="coerce")

if "y" in panel_mstl.columns:
    panel_mstl["y"] = pd.to_numeric(panel_mstl["y"], errors="coerce")

write_df_fast(panel_mstl, "stg_mstl_product_month", chunk_size=50000)


# In[27]:


write_df_fast(panel_export, "stg_feature_engineered_panel", chunk_size=50000)


# In[28]:


# Verification
print('stg_feature_engineered_panel rows:', verify_table('stg_feature_engineered_panel'))
print('stg_mstl_product_month rows:', verify_table('stg_mstl_product_month'))


# In[ ]:




