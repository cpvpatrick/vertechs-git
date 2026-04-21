#!/usr/bin/env python
# coding: utf-8

# # LightGBM Forecasting Pipeline
# 
# This notebook trains the global LightGBM forecasting model, evaluates validation and test performance, generates 3-month future forecasts, and exports outputs for the frontend and database.

# ## 1. Imports and Global Setup

# In[1]:


import pyopencl as cl

platforms = cl.get_platforms()
for p in platforms:
    print("Platform:", p.name)
    for d in p.get_devices():
        print("  Device:", d.name)


# In[2]:


import lightgbm as lgb
import numpy as np

try:
    train_data = lgb.Dataset(
        np.array([[1,2],[3,4]]),
        label=np.array([1,2])
    )

    lgb.train(
        {"device": "gpu"},
        train_data,
        num_boost_round=1
    )

    print("GPU works ✅")

except Exception as e:
    print("GPU failed ❌:", e)


# In[3]:


import pandas as pd
import numpy as np
import lightgbm as lgb
import matplotlib.pyplot as plt

from pathlib import Path
from sklearn.metrics import mean_absolute_error, mean_squared_error

import ipywidgets as widgets
from IPython.display import display, clear_output
from tqdm.auto import tqdm


# In[4]:


USE_GPU = True
GPU_DEVICE_ID = 0

USE_GPU_FOR_CV = False
USE_GPU_FOR_FINAL = True

CV_N_ESTIMATORS = 2200
FINAL_N_ESTIMATORS = 3000
N_CV_MONTHS = 3


# ## 2. Database Helpers and Shared Utilities

# In[5]:


# ============================================================
# DB HELPERS (MEMORY-AWARE READ/WRITE)
# ============================================================
from sqlalchemy import create_engine, text
from io import StringIO
import csv
import math
import gc
import re
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
    if df is None:
        return df
    out = df.copy()
    out.columns = (
        out.columns
        .astype(str)
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

    cols = {k: v for k, v in rename_map.items() if k in out.columns and v not in out.columns}
    return out.rename(columns=cols)

def optimize_dataframe_memory(df, category_threshold=0.20):
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
    return out.replace({pd.NA: None})

def verify_table(table_name, schema=SCHEMA):
    q = text(f'SELECT COUNT(*) AS n FROM "{schema}"."{table_name}"')
    with engine.begin() as conn:
        return int(pd.read_sql(q, conn).iloc[0, 0])

def _parse_table_ref(table_ref, default_schema=SCHEMA):
    if "." in table_ref:
        schema, table = table_ref.split(".", 1)
    else:
        schema, table = default_schema, table_ref
    return schema.strip('"'), table.strip('"')

def _normalize_name(name):
    return re.sub(r"[^a-z0-9]", "", str(name).lower())

def get_existing_columns(table_ref, schema=None):
    schema_name, table_name = _parse_table_ref(table_ref, default_schema=schema or SCHEMA)
    q = text("""
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = :schema_name
          AND table_name = :table_name
        ORDER BY ordinal_position
    """)
    with engine.begin() as conn:
        cols = pd.read_sql(q, conn, params={"schema_name": schema_name, "table_name": table_name})["column_name"].tolist()
    if not cols:
        raise ValueError(f"No columns found for {schema_name}.{table_name}")
    return cols

def resolve_existing_columns(table_ref, candidate_cols, schema=None):
    existing_cols = get_existing_columns(table_ref, schema=schema)
    normalized_to_actual = {_normalize_name(col): col for col in existing_cols}

    selected = []
    seen = set()
    for cand in candidate_cols:
        actual = normalized_to_actual.get(_normalize_name(cand))
        if actual and actual not in seen:
            selected.append(actual)
            seen.add(actual)
    return selected

def build_select_query(table_ref, candidate_cols, where=None, order_by=None, schema=None):
    schema_name, table_name = _parse_table_ref(table_ref, default_schema=schema or SCHEMA)
    selected = resolve_existing_columns(table_ref, candidate_cols, schema=schema)
    if not selected:
        raise ValueError(f"No requested columns found in {schema_name}.{table_name}")

    select_cols = ",\n    ".join([f'"{col}"' for col in selected])
    query = f'SELECT\n    {select_cols}\nFROM "{schema_name}"."{table_name}"'
    if where:
        query += f"\nWHERE {where}"
    if order_by:
        query += f"\nORDER BY {order_by}"
    return query, selected

def read_sql_small(query, parse_dates=None, label="query", optimize_memory=True):
    sql = text(query) if isinstance(query, str) else query
    df = pd.read_sql_query(sql, engine, parse_dates=parse_dates)
    if optimize_memory:
        df = optimize_dataframe_memory(df)
    print(f"[ok] Loaded {label}: {df.shape}")
    mem_mb = df.memory_usage(deep=True).sum() / (1024 ** 2)
    print(f"[mem] {label}: {mem_mb:,.2f} MB")
    return df

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
    mem_mb = df.memory_usage(deep=True).sum() / (1024 ** 2)
    print(f"[ok] Loaded {label}: {df.shape}")
    print(f"[mem] {label}: {mem_mb:,.2f} MB")
    return df

def safe_read_sql(query: str, parse_dates=None, label: str = "query", chunksize=25000):
    try:
        df = read_sql_fast(
            query,
            parse_dates=parse_dates,
            chunksize=chunksize,
            label=label,
        )
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
    total_chunks = math.ceil(total_rows / chunk_size)

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

        sample_out = _normalize_for_sql(df.head(0))
        sample_out.to_sql(
            table_name,
            engine,
            schema=schema,
            if_exists="replace" if if_exists == "replace" else "append",
            index=False,
        )

        cols = ",".join([f'"{c}"' for c in sample_out.columns])

        for start in tqdm(range(0, total_rows, chunk_size), desc=f"Writing {table_name}", unit="chunk", total=total_chunks):
            chunk = df.iloc[start:start + chunk_size].copy()
            chunk = _normalize_for_sql(chunk)
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
        fallback_out = _normalize_for_sql(df)
        fallback_out.to_sql(
            table_name,
            engine,
            schema=schema,
            if_exists=if_exists,
            index=False,
            method="multi",
            chunksize=10000,
        )
        written = verify_table(table_name, schema=schema)
        print(f"[ok] {schema}.{table_name}: {written:,} rows written via chunked to_sql")
        return written

    finally:
        if cur is not None:
            cur.close()
        if raw is not None:
            raw.close()

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


# In[6]:


# Column standardization is defined once above in the DB HELPERS cell.
print("Using shared standardize_columns() helper from DB HELPERS.")


# In[7]:


# ============================================================
# INPUT / OUTPUT CONFIG (DB-ONLY)
# ============================================================
PANEL_TABLE = f'{SCHEMA}.stg_feature_engineered_panel'
MSTL_DECOMP_TABLE = f'{SCHEMA}.stg_mstl_decomposition_for_lightgbm'
MSTL_PROFILE_TABLE = f'{SCHEMA}.stg_mstl_seasonal_profiles'

# GPU config
USE_GPU = True
GPU_DEVICE_ID = 0

print("PANEL_TABLE:", PANEL_TABLE)
print("MSTL_DECOMP_TABLE:", MSTL_DECOMP_TABLE)
print("MSTL_PROFILE_TABLE:", MSTL_PROFILE_TABLE)
print("USE_GPU:", USE_GPU, "| GPU_DEVICE_ID:", GPU_DEVICE_ID)


# ## 3. Load Feature Store and MSTL Inputs

# In[8]:


# ============================================================
# NARROW DB LOADS (RAM-SAFER)
# ============================================================
panel_candidate_cols = [
    "ds",
    "split",
    "net_sales_ty",
    "product_code",
    "store_code",
    "nestle_store_cluster",
    "nestle_region",
    "store_description",
    "product_description",
    "brand",
    "category",
    "month",
    "quarter",
    "year",
    "month_sin",
    "month_cos",
    "lag_1",
    "lag_2",
    "lag_3",
    "lag_6",
    "lag_12",
    "rolling_mean_3",
    "rolling_mean_6",
    "rolling_mean_12",
    "rolling_std_3",
    "rolling_std_6",
    "rolling_std_12",
    "trend_growth",
    "rolling_growth_3",
    "is_observed_month",
    "is_gap_filled",
    "history_months_train",
    "nonzero_months_train",
    "demand_share_train",
    "total_sales_train",
    "filled_gap_share_train",
    "eligible_for_model",
    "eligible_for_strict_eval",
    "eligibility_tier",
]

mstl_decomp_candidate_cols = [
    "product_code",
    "ds",
    "trend",
    "seasonal_strength",
]

mstl_profile_candidate_cols = [
    "product_code",
    "month",
    "seasonal_multiplier",
]

panel_query, panel_loaded_cols = build_select_query(
    PANEL_TABLE,
    panel_candidate_cols,
    order_by='"ds", "Product Code", "Store Code"' if '"Product Code"' in [f'"{c}"' for c in get_existing_columns(PANEL_TABLE)] else None,
)
mstl_decomp_query, mstl_decomp_loaded_cols = build_select_query(
    MSTL_DECOMP_TABLE,
    mstl_decomp_candidate_cols,
)
mstl_profile_query, mstl_profile_loaded_cols = build_select_query(
    MSTL_PROFILE_TABLE,
    mstl_profile_candidate_cols,
)

print("Panel columns selected:", panel_loaded_cols)
print("MSTL decomp columns selected:", mstl_decomp_loaded_cols)
print("MSTL profile columns selected:", mstl_profile_loaded_cols)

panel = standardize_columns(
    read_sql_fast(
        panel_query,
        parse_dates=["ds"],
        label="panel",
    )
)
mstl_decomp = standardize_columns(
    read_sql_fast(
        mstl_decomp_query,
        parse_dates=["ds"],
        label="mstl_decomp",
    )
)
mstl_profile = standardize_columns(
    read_sql_fast(
        mstl_profile_query,
        label="mstl_profile",
    )
)

print(panel.shape)
print(mstl_decomp.shape)
print(mstl_profile.shape)
print("\nSample standardized columns:")
print("panel      :", panel.columns[:12].tolist())
print("mstl_decomp:", mstl_decomp.columns[:12].tolist())
print("mstl_profile:", mstl_profile.columns[:12].tolist())


# ## 4. Standardize Keys and Merge MSTL Features

# In[9]:


# Standardize merge keys and core dtypes once before any merge or model prep.
for df_name, df_obj in [("panel", panel), ("mstl_decomp", mstl_decomp), ("mstl_profile", mstl_profile)]:
    if "product_code" in df_obj.columns:
        df_obj["product_code"] = df_obj["product_code"].astype(str).str.zfill(9)

    if "store_code" in df_obj.columns:
        df_obj["store_code"] = df_obj["store_code"].astype(str)

    if "ds" in df_obj.columns:
        df_obj["ds"] = pd.to_datetime(df_obj["ds"], errors="coerce")

if "month" in mstl_profile.columns:
    mstl_profile["month"] = pd.to_numeric(mstl_profile["month"], errors="coerce").astype("Int64")

print("Key standardization complete.")


# In[10]:


print("mstl_profile columns:", list(mstl_profile.columns))
print("mstl_decomp columns:", list(mstl_decomp.columns))


# In[11]:


# lookup tables for future forecasting
seasonal_map = (
    mstl_profile
    .drop_duplicates(subset=["product_code", "month"])
    .set_index(["product_code", "month"])["seasonal_multiplier"]
)

strength_map = (
    mstl_decomp
    .drop_duplicates(subset=["product_code", "ds"])
    .groupby("product_code")["seasonal_strength"]
    .mean()
)

print("Lookup tables ready for future forecasting.")
print("Seasonal keys:", len(seasonal_map))
print("Strength keys:", len(strength_map))


# In[12]:


# remove existing MSTL columns first so this cell is safe to rerun
for c in [
    "trend", "seasonal_multiplier", "seasonal_strength",
    "seasonal_baseline", "seasonal_lag_12", "seasonal_lag_1"
]:
    if c in panel.columns:
        panel = panel.drop(columns=c)

panel = panel.merge(
    mstl_decomp[["product_code", "ds", "trend", "seasonal_strength"]],
    on=["product_code", "ds"],
    how="left"
)

panel = panel.merge(
    mstl_profile[["product_code", "month", "seasonal_multiplier"]],
    on=["product_code", "month"],
    how="left"
)

trend_missing = panel["trend"].isna().mean()
seasonal_missing = panel["seasonal_multiplier"].isna().mean()
strength_missing = panel["seasonal_strength"].isna().mean()

print("MSTL merge coverage before fallback")
print("trend missing rate               :", f"{trend_missing:.2%}")
print("seasonal_multiplier missing rate :", f"{seasonal_missing:.2%}")
print("seasonal_strength missing rate   :", f"{strength_missing:.2%}")

panel["trend"] = pd.to_numeric(panel["trend"], errors="coerce").fillna(0.0).astype("float32")
panel["seasonal_multiplier"] = pd.to_numeric(panel["seasonal_multiplier"], errors="coerce").fillna(1.0).astype("float32")
panel["seasonal_strength"] = pd.to_numeric(panel["seasonal_strength"], errors="coerce").fillna(0.0).clip(0.0, 1.0).astype("float32")

panel["seasonal_baseline"] = (panel["trend"] * panel["seasonal_multiplier"]).astype("float32")
panel["seasonal_lag_12"] = (panel["lag_12"] * panel["seasonal_multiplier"]).astype("float32")
panel["seasonal_lag_1"] = (panel["lag_1"] * panel["seasonal_multiplier"]).astype("float32")

panel["fallback_forecast"] = np.where(
    panel["lag_12"].notna(),
    panel["lag_12"],
    panel["lag_1"]
)
panel["fallback_forecast"] = pd.to_numeric(panel["fallback_forecast"], errors="coerce").fillna(0.0).clip(lower=0.0).astype("float32")

seasonal_lookup = pd.DataFrame(columns=["product_code", "month", "seasonal_multiplier"])
if {"product_code", "month", "seasonal_multiplier"}.issubset(mstl_profile.columns):
    seasonal_lookup = (
        mstl_profile[["product_code", "month", "seasonal_multiplier"]]
        .dropna(subset=["product_code", "month"])
        .copy()
    )
    seasonal_lookup["month"] = pd.to_numeric(seasonal_lookup["month"], errors="coerce").astype("Int64")
    seasonal_lookup["seasonal_multiplier"] = pd.to_numeric(
        seasonal_lookup["seasonal_multiplier"], errors="coerce"
    ).fillna(1.0).astype("float32")
    seasonal_lookup = seasonal_lookup.drop_duplicates(subset=["product_code", "month"], keep="last")

seasonal_month_maps = {
    int(month_num): grp.set_index("product_code")["seasonal_multiplier"].astype("float32")
    for month_num, grp in seasonal_lookup.groupby("month", observed=True)
    if pd.notna(month_num)
}

strength_map = pd.Series(dtype="float32")
latest_trend_map = pd.Series(dtype="float32")

if {"product_code", "seasonal_strength", "ds"}.issubset(mstl_decomp.columns):
    tmp_strength = (
        mstl_decomp.sort_values(["product_code", "ds"])
        .dropna(subset=["product_code"])
        .groupby("product_code", sort=False)["seasonal_strength"]
        .last()
    )
    strength_map = pd.to_numeric(tmp_strength, errors="coerce").fillna(0.0).astype("float32")

if {"product_code", "trend", "ds"}.issubset(mstl_decomp.columns):
    tmp_trend = (
        mstl_decomp.sort_values(["product_code", "ds"])
        .dropna(subset=["product_code"])
        .groupby("product_code", sort=False)["trend"]
        .last()
    )
    latest_trend_map = pd.to_numeric(tmp_trend, errors="coerce").fillna(0.0).astype("float32")

print("Panel shape after MSTL attachment:", panel.shape)
display(panel[[
    "product_code", "ds", "month",
    "trend", "seasonal_multiplier", "seasonal_strength",
    "seasonal_baseline", "seasonal_lag_12", "seasonal_lag_1",
    "fallback_forecast"
]].head())

del mstl_decomp, mstl_profile
gc.collect()


# In[13]:


print("Load and merge QA")
print("panel shape               :", panel.shape)
print("Panel columns after merge :", len(panel.columns))
print(
    "Missing trend share       :",
    f'{panel["trend"].isna().mean():.2%}' if "trend" in panel.columns else "n/a"
)
print(
    "Missing seasonal profile  :",
    f'{panel["seasonal_multiplier"].isna().mean():.2%}' if "seasonal_multiplier" in panel.columns else "n/a"
)
print(
    "Missing seasonal strength :",
    f'{panel["seasonal_strength"].isna().mean():.2%}' if "seasonal_strength" in panel.columns else "n/a"
)

available_cols = [
    c for c in [
        "product_code", "ds", "month",
        "trend", "seasonal_multiplier", "seasonal_strength",
        "seasonal_baseline", "seasonal_lag_12", "seasonal_lag_1",
        "fallback_forecast"
    ]
    if c in panel.columns
]

display(panel[available_cols].head())


# ## 5. Validate Required Columns and Build Feature List

# In[14]:


required_core_cols = [
    "ds",
    "split",
    "net_sales_ty",
    "product_code",
    "store_code",
]

missing_core_cols = [c for c in required_core_cols if c not in panel.columns]
if missing_core_cols:
    raise ValueError(f"Missing required columns in panel: {missing_core_cols}")

required_defaults = {
    "history_months_train": 0,
    "eligible_for_model": 1,
    "nonzero_months_train": 0,
    "filled_gap_share_train": 0.0,
    "demand_share_train": 0.0,
    "total_sales_train": 0.0,
    "eligible_for_strict_eval": 0,
    "is_observed_month": 0,
    "is_gap_filled": 0,
}

for col, default in required_defaults.items():
    if col not in panel.columns:
        panel[col] = default

candidate_features = [
    "store_code",
    "product_code",
    "nestle_store_cluster",
    "nestle_region",
    "month",
    "quarter",
    "year",
    "month_sin",
    "month_cos",

    "lag_1",
    "lag_2",
    "lag_3",
    "lag_6",
    "lag_12",

    "rolling_mean_3",
    "rolling_mean_6",
    "rolling_mean_12",

    "rolling_std_3",
    "rolling_std_6",
    "rolling_std_12",

    "trend",
    "seasonal_multiplier",
    "seasonal_strength",
    "seasonal_baseline",
    "seasonal_lag_1",

    "trend_growth",
    "rolling_growth_3",

    "is_observed_month",
    "is_gap_filled",
    "history_months_train",
    "nonzero_months_train",
    "demand_share_train",
    "total_sales_train",
    "filled_gap_share_train",
    "eligible_for_strict_eval"
]

required_model_features = [
    "product_code",
    "store_code",
    "month",
    "quarter",
    "year",
    "lag_1",
    "lag_12",
    "history_months_train",
]

missing_required_features = [c for c in required_model_features if c not in panel.columns]
if missing_required_features:
    raise ValueError(f"Missing required model features from FE export: {missing_required_features}")

features = [c for c in candidate_features if c in panel.columns]

for leak_col in ["net_sales_ty", "units_sold_ty", "fallback_forecast", "eligible_for_model", "eligibility_tier"]:
    if leak_col in features:
        features.remove(leak_col)

candidate_cat_cols = [
    "store_code",
    "product_code",
    "nestle_store_cluster",
    "nestle_region"
]

# Keep Store Code in features, but do NOT treat it as categorical
cat_cols = [c for c in candidate_cat_cols if c in features and c != "store_code"]

numeric_feature_fill = {
    "lag_1": 0.0,
    "lag_2": 0.0,
    "lag_3": 0.0,
    "lag_6": 0.0,
    "lag_12": 0.0,
    "rolling_mean_3": 0.0,
    "rolling_mean_6": 0.0,
    "rolling_mean_12": 0.0,
    "rolling_std_3": 0.0,
    "rolling_std_6": 0.0,
    "rolling_std_12": 0.0,
    "trend": 0.0,
    "seasonal_multiplier": 1.0,
    "seasonal_strength": 0.0,
    "seasonal_baseline": 0.0,
    "seasonal_lag_1": 0.0,
    "trend_growth": 0.0,
    "rolling_growth_3": 0.0,
    "is_observed_month": 0.0,
    "is_gap_filled": 0.0,
    "history_months_train": 0.0,
    "nonzero_months_train": 0.0,
    "demand_share_train": 0.0,
    "total_sales_train": 0.0,
    "filled_gap_share_train": 0.0,
    "eligible_for_strict_eval": 0.0
}

def preprocess_model_frame(df, feature_cols, categorical_cols):
    out = df.copy()

    # -----------------------------------
    # 1) CREATE MISSING COLUMNS FIRST ✅
    # -----------------------------------
    for c in feature_cols:
        if c not in out.columns:
            if c in categorical_cols:
                out[c] = "unknown"
            else:
                out[c] = 0.0

    # -----------------------------------
    # 2) HANDLE CATEGORICAL FEATURES
    # -----------------------------------
    for c in categorical_cols:
        if c not in out.columns:
            out[c] = "unknown"
        out[c] = out[c].astype(str).fillna("unknown")

    # -----------------------------------
    # 3) HANDLE NUMERIC FEATURES
    # -----------------------------------
    numeric_feature_fill = {
        "seasonal_multiplier": 1.0,  # only special case
    }

    for c in feature_cols:
        if c in categorical_cols:
            continue

        fill_value = numeric_feature_fill.get(c, 0.0)

        out[c] = (
            pd.to_numeric(out[c], errors="coerce")
            .fillna(fill_value)
            .astype("float32")
        )

    # -----------------------------------
    # 4) ENSURE FALLBACK EXISTS
    # -----------------------------------
    if "fallback_forecast" not in out.columns:
        lag12 = pd.to_numeric(out.get("lag_12", 0), errors="coerce")
        lag1 = pd.to_numeric(out.get("lag_1", 0), errors="coerce")

        out["fallback_forecast"] = (
            lag12.fillna(lag1)
            .fillna(0.0)
            .astype("float32")
        )

    return out
print("Feature count:", len(features))
print(features)
print("\nCategorical columns:")
print(cat_cols)


# In[15]:


panel.columns = [str(c).strip().replace(" ", "_") for c in panel.columns]

if "mstl_decomp" in locals():
    mstl_decomp.columns = [str(c).strip().replace(" ", "_") for c in mstl_decomp.columns]

if "mstl_profile" in locals():
    mstl_profile.columns = [str(c).strip().replace(" ", "_") for c in mstl_profile.columns]


# In[16]:


target = "net_sales_ty"

features = [str(c).strip().replace(" ", "_") for c in features]
cat_cols = [str(c).strip().replace(" ", "_") for c in cat_cols]


# ## 6. Prepare Modeling Frame

# In[17]:


panel_model = preprocess_model_frame(panel, features, cat_cols)

print("Model frame QA")
print("panel_model shape :", panel_model.shape)
print("Eligible row share:", f'{panel_model["eligible_for_model"].mean():.2%}')
print("Unique SKUs       :", panel_model["product_code"].nunique())
print("Unique stores     :", panel_model["store_code"].nunique())
print("Date range        :", panel_model["ds"].min(), "to", panel_model["ds"].max())

if "eligibility_tier" in panel_model.columns:
    display(
        panel_model["eligibility_tier"]
        .value_counts(dropna=False)
        .rename_axis("eligibility_tier")
        .to_frame("row_count")
    )


# ## 7. Train / Validation / Test Split

# In[18]:


target = "net_sales_ty"

train_df = panel_model[panel_model["split"] == "train"].copy()
val_df = panel_model[panel_model["split"].isin(["val", "valid"])].copy()
test_df = panel_model[panel_model["split"] == "test"].copy()

for frame in [train_df, val_df, test_df]:
    frame[target] = pd.to_numeric(frame[target], errors="coerce")

train_df = train_df.dropna(subset=[target]).copy()
val_df = val_df.dropna(subset=[target]).copy()
test_df = test_df.dropna(subset=[target]).copy()

model_train_df = train_df[train_df["eligible_for_model"] == 1].copy()
model_val_df = val_df[val_df["eligible_for_model"] == 1].copy()
model_test_df = test_df[test_df["eligible_for_model"] == 1].copy()

print("Train / validation / test QA")
print("All rows")
print("Train:", train_df.shape)
print("Val  :", val_df.shape)
print("Test :", test_df.shape)

print("\nEligible rows used for LightGBM")
print("Train:", model_train_df.shape)
print("Val  :", model_val_df.shape)
print("Test :", model_test_df.shape)

print("\nMissing values in eligible train features")
display(model_train_df[features + [target]].isna().sum().sort_values(ascending=False).to_frame("missing_count"))

X_train = model_train_df[features].copy()
X_val = model_val_df[features].copy()
X_test = model_test_df[features].copy()

y_train = model_train_df[target].clip(lower=0).astype("float32")
y_val = model_val_df[target].clip(lower=0).astype("float32")
y_test = model_test_df[target].clip(lower=0).astype("float32")

tier_weight_map = {"strong": 1.00, "medium": 0.85, "weak": 0.70}

train_tier_weight = model_train_df["eligibility_tier"].map(tier_weight_map).fillna(0.85).astype("float32")
val_tier_weight = model_val_df["eligibility_tier"].map(tier_weight_map).fillna(0.85).astype("float32")

train_weights = (np.sqrt(np.maximum(y_train, 1.0)).astype("float32") * train_tier_weight)
val_weights = (np.sqrt(np.maximum(y_val, 1.0)).astype("float32") * val_tier_weight)


# ## 8. LightGBM Training

# In[19]:


cardinality_check = pd.DataFrame({
    "feature": features,
    "nunique_train": [X_train[c].nunique(dropna=True) for c in features],
    "dtype": [str(X_train[c].dtype) for c in features],
}).sort_values("nunique_train", ascending=False)

display(cardinality_check.head(20))


# In[20]:


bin_check = []

for col in features:
    nunique = X_train[col].nunique(dropna=True)
    bin_check.append({
        "feature": col,
        "nunique": nunique,
        "risk": "HIGH" if nunique > 255 else "OK"
    })

bin_check_df = pd.DataFrame(bin_check).sort_values("nunique", ascending=False)

display(bin_check_df.head(20))


# In[21]:


problem_features = []

for col in tqdm(features, desc="GPU bin test"):
    try:
        X_tmp = X_train[[col]].copy()
        y_tmp = y_train.copy()

        model = lgb.LGBMRegressor(
            objective="regression",
            n_estimators=10,
            device_type="gpu",
            gpu_device_id=GPU_DEVICE_ID,
            max_bin=255,
        )

        model.fit(X_tmp, y_tmp)

    except Exception as e:
        if "cannot run on GPU" in str(e):
            problem_features.append(col)

print("Problematic features:")
print(problem_features)


# In[22]:


def get_lgbm_params(use_gpu=True, n_estimators=3000):
    params = {
        "objective": "regression_l1",
        "metric": "rmse",
        "n_estimators": n_estimators,
        "learning_rate": 0.015,
        "num_leaves": 63,
        "max_depth": -1,
        "min_child_samples": 80,
        "subsample": 0.8,
        "subsample_freq": 1,
        "colsample_bytree": 0.8,
        "reg_alpha": 1.0,
        "reg_lambda": 2.5,
        "random_state": 42,
        "n_jobs": -1,
    }

    if use_gpu:
        params.update({
            "device_type": "gpu",
            "gpu_device_id": GPU_DEVICE_ID,
            "max_bin": 63,
        })
    else:
        params.update({
            "force_row_wise": True,
        })

    return params


# In[23]:


def gpu_training_available():
    if not USE_GPU:
        return False, "GPU disabled by config."

    try:
        X_probe = pd.DataFrame({
            "x1": np.array([0.0, 1.0, 2.0, 3.0], dtype="float32"),
            "x2": np.array([1.0, 0.0, 1.0, 0.0], dtype="float32"),
        })
        y_probe = np.array([0.0, 1.0, 2.0, 3.0], dtype="float32")

        probe_model = lgb.LGBMRegressor(
            objective="regression",
            n_estimators=5,
            learning_rate=0.1,
            device_type="gpu",
            gpu_device_id=GPU_DEVICE_ID,
            max_bin=255,
            n_jobs=-1,
            random_state=42,
        )
        probe_model.fit(X_probe, y_probe)
        return True, "LightGBM GPU probe succeeded."
    except Exception as e:
        return False, f"GPU probe failed, falling back to CPU. Details: {e}"

GPU_READY, GPU_MESSAGE = gpu_training_available()
print(GPU_MESSAGE)


# In[24]:


class TqdmLightGBMCallback:
    def __init__(self, total_rounds, desc="LightGBM training"):
        self.total_rounds = total_rounds
        self.desc = desc
        self.pbar = None

    def __call__(self, env):
        if self.pbar is None:
            self.pbar = tqdm(total=self.total_rounds, desc=self.desc, unit="iter")

        current_iter = env.iteration + 1
        self.pbar.n = min(current_iter, self.total_rounds)

        if env.evaluation_result_list:
            metric_parts = []
            for item in env.evaluation_result_list:
                data_name, metric_name, metric_value, *_ = item
                metric_parts.append(f"{data_name}_{metric_name}={metric_value:.4f}")
            self.pbar.set_postfix_str(" | ".join(metric_parts))

        self.pbar.refresh()

        if current_iter >= self.total_rounds:
            self.pbar.close()
            self.pbar = None

    order = 20
    before_iteration = False


# In[25]:


def build_lgbm_model(params):
    params = params.copy()

    if params.get("device_type") == "gpu" and not GPU_READY:
        params.pop("device_type", None)
        params.pop("gpu_device_id", None)
        params.pop("max_bin", None)

    return lgb.LGBMRegressor(**params)

def build_lgbm_callbacks(model_params, desc, log_period=250, early_stop_rounds=100):
    return [
        lgb.log_evaluation(log_period),
        lgb.early_stopping(early_stop_rounds),
        TqdmLightGBMCallback(total_rounds=model_params["n_estimators"], desc=desc),
    ]


# In[26]:


tier_weight_map = {"strong": 1.00, "medium": 0.85, "weak": 0.70}

def build_sample_weights(y, tier_series):
    return (
        np.sqrt(np.maximum(y, 1.0)).astype("float32")
        * tier_series.map(tier_weight_map).fillna(0.85).astype("float32")
    )


# In[27]:


cv_source = panel_model[
    (panel_model["eligible_for_model"] == 1) &
    (panel_model["split"].isin(["train", "val", "valid"]))
].copy()

cv_source["ds"] = pd.to_datetime(cv_source["ds"]).dt.to_period("M").dt.to_timestamp()

candidate_months = sorted(cv_source["ds"].dropna().unique())
cv_months = candidate_months[-N_CV_MONTHS:] if len(candidate_months) >= N_CV_MONTHS else candidate_months

print("CV months:", [pd.Timestamp(x).strftime("%Y-%m-%d") for x in cv_months])


# In[28]:


# make sure categorical columns are pandas category dtype
for col in cat_cols:
    if col in cv_source.columns:
        cv_source[col] = cv_source[col].astype("category")


# In[29]:


cv_params = get_lgbm_params(use_gpu=USE_GPU_FOR_CV, n_estimators=CV_N_ESTIMATORS)

cv_rows = []

for cutoff_month in tqdm(cv_months, desc="Rolling validation", unit="month"):
    fold_train = cv_source.loc[cv_source["ds"] < cutoff_month].copy()
    fold_val = cv_source.loc[cv_source["ds"] == cutoff_month].copy()

    if fold_train.empty or fold_val.empty:
        print(f"[skip] {cutoff_month}: train={len(fold_train)}, val={len(fold_val)}")
        continue

    # align categorical columns safely for each fold
    for col in cat_cols:
        if col in fold_train.columns and col in fold_val.columns:
            all_cats = pd.Index(
                pd.concat([fold_train[col], fold_val[col]], axis=0)
                .astype("string")
                .fillna("__MISSING__")
                .unique()
            )
            fold_train[col] = pd.Categorical(
                fold_train[col].astype("string").fillna("__MISSING__"),
                categories=all_cats
            )
            fold_val[col] = pd.Categorical(
                fold_val[col].astype("string").fillna("__MISSING__"),
                categories=all_cats
            )

    X_fold_train = fold_train[features]
    y_fold_train = fold_train[target].clip(lower=0).astype("float32")
    w_fold_train = build_sample_weights(y_fold_train, fold_train["eligibility_tier"])

    X_fold_val = fold_val[features]
    y_fold_val = fold_val[target].clip(lower=0).astype("float32")
    w_fold_val = build_sample_weights(y_fold_val, fold_val["eligibility_tier"])

    fold_model = build_lgbm_model(cv_params)

    fold_model.fit(
        X_fold_train,
        y_fold_train,
        sample_weight=w_fold_train,
        eval_set=[(X_fold_val, y_fold_val)],
        eval_sample_weight=[w_fold_val],
        eval_metric="rmse",
        categorical_feature=[c for c in cat_cols if c in X_fold_train.columns],
        callbacks=build_lgbm_callbacks(
            cv_params,
            desc=f"CV {pd.Timestamp(cutoff_month).strftime('%Y-%m')}",
            log_period=250,
            early_stop_rounds=100,
        )
    )

    fold_pred = np.maximum(fold_model.predict(X_fold_val), 0.0)

    cv_rows.append({
        "validation_month": pd.Timestamp(cutoff_month),
        "rmse": float(np.sqrt(mean_squared_error(y_fold_val, fold_pred))),
        "mae": float(mean_absolute_error(y_fold_val, fold_pred)),
        "smape": float(
            np.mean(
                np.abs(y_fold_val - fold_pred) /
                np.maximum((np.abs(y_fold_val) + np.abs(fold_pred)) / 2.0, 1.0)
            ) * 100.0
        ),
        "mode": "cpu"
    })

    del fold_train, fold_val, X_fold_train, X_fold_val, y_fold_train, y_fold_val, w_fold_train, w_fold_val, fold_model, fold_pred
    gc.collect()

print("cv_rows length:", len(cv_rows))

cv_results = pd.DataFrame(cv_rows)

if not cv_results.empty:
    print("Rolling validation summary")
    display(cv_results)
    print("Average CV metrics:")
    display(cv_results[["rmse", "mae", "smape"]].mean().to_frame("mean_metric"))
else:
    print("Rolling validation skipped because there were not enough eligible months.")


# In[30]:


cv_results = pd.DataFrame(cv_rows)

if not cv_results.empty:
    print("Rolling validation summary")
    display(cv_results)
    print("Average CV metrics:")
    display(cv_results[["rmse", "mae", "smape"]].mean().to_frame("mean_metric"))
else:
    print("Rolling validation skipped because there were not enough eligible months.")


# In[31]:


def fit_lgbm_with_gpu_fallback(
    model,
    X_train,
    y_train,
    w_train,
    X_val,
    y_val,
    w_val,
    cat_cols,
    callbacks,
    desc=""
):
    try:
        model.fit(
            X_train,
            y_train,
            sample_weight=w_train,
            eval_set=[(X_val, y_val)],
            eval_sample_weight=[w_val],
            eval_metric="rmse",
            categorical_feature=cat_cols,
            callbacks=callbacks,
        )
        return model, "gpu"
    except Exception as e:
        msg = str(e)
        if "cannot run on GPU" in msg or "bin size" in msg:
            print(f"[warn] {desc}: GPU failed due to bin constraint. Retrying on CPU.")

            cpu_params = get_lgbm_params(use_gpu=False, n_estimators=FINAL_N_ESTIMATORS)
            cpu_model = lgb.LGBMRegressor(**cpu_params)
            cpu_model.fit(
                X_train,
                y_train,
                sample_weight=w_train,
                eval_set=[(X_val, y_val)],
                eval_sample_weight=[w_val],
                eval_metric="rmse",
                categorical_feature=cat_cols,
                callbacks=build_lgbm_callbacks(
                    cpu_params,
                    desc=f"{desc} [CPU fallback]",
                    log_period=100,
                    early_stop_rounds=100,
                ),
            )
            return cpu_model, "cpu_fallback"
        raise


# In[33]:


# enforce categorical dtype
for col in cat_cols:
    if col in X_train.columns:
        X_train[col] = X_train[col].astype("category")
        X_val[col] = X_val[col].astype("category")

        # align categories
        X_val[col] = X_val[col].cat.set_categories(X_train[col].cat.categories)


# In[34]:


final_model_params = get_lgbm_params(
    use_gpu=USE_GPU_FOR_FINAL,
    n_estimators=FINAL_N_ESTIMATORS
)

model = build_lgbm_model(final_model_params)

model, final_mode = fit_lgbm_with_gpu_fallback(
    model=model,
    X_train=X_train,
    y_train=y_train,
    w_train=train_weights,
    X_val=X_val,
    y_val=y_val,
    w_val=val_weights,
    cat_cols=cat_cols,
    callbacks=build_lgbm_callbacks(
        final_model_params,
        desc="Final LightGBM training",
        log_period=100,
        early_stop_rounds=100,
    ),
    desc="Final LightGBM training"
)

print("Final training mode:", final_mode)


# ## 9. Prediction, Fallback Logic, and Evaluation

# In[35]:


def smape(y_true, y_pred):
    y_true = np.array(y_true, dtype=float)
    y_pred = np.array(y_pred, dtype=float)

    epsilon = 1.0
    denominator = (np.abs(y_true) + np.abs(y_pred)) / 2
    denominator = np.maximum(denominator, epsilon)

    return np.mean(np.abs(y_true - y_pred) / denominator) * 100


# In[36]:


def predict_with_fallback(prepared_df):
    out = prepared_df.copy()
    out["base_forecast"] = out["fallback_forecast"].astype("float32")

    eligible_mask = out["eligible_for_model"].eq(1)

    if eligible_mask.any():
        pred_input = out.loc[eligible_mask, features].copy()
        for c in cat_cols:
            pred_input[c] = pred_input[c].astype("category")
        model_pred = np.maximum(model.predict(pred_input), 0.0).astype("float32")
        out.loc[eligible_mask, "base_forecast"] = model_pred

    out["base_forecast"] = out["base_forecast"].clip(lower=0.0)
    return out


val_scored = predict_with_fallback(val_df)
test_scored = predict_with_fallback(test_df)

rmse_val = np.sqrt(mean_squared_error(val_scored[target], val_scored["base_forecast"]))
mae_val = mean_absolute_error(val_scored[target], val_scored["base_forecast"])
smape_val = smape(val_scored[target], val_scored["base_forecast"])

rmse_test = np.sqrt(mean_squared_error(test_scored[target], test_scored["base_forecast"]))
mae_test = mean_absolute_error(test_scored[target], test_scored["base_forecast"])
smape_test = smape(test_scored[target], test_scored["base_forecast"])

print("Evaluation summary")
print("VALIDATION")
print("RMSE:", rmse_val)
print("MAE:", mae_val)
print("sMAPE:", smape_val)

print("\nTEST")
print("RMSE:", rmse_test)
print("MAE:", mae_test)
print("sMAPE:", smape_test)

if "eligible_for_strict_eval" in val_scored.columns:
    strict_val = val_scored[val_scored["eligible_for_strict_eval"] == 1].copy()
    strict_test = test_scored[test_scored["eligible_for_strict_eval"] == 1].copy()

    if not strict_val.empty:
        print("\nSTRICT VALIDATION (strong series only)")
        print("RMSE:", np.sqrt(mean_squared_error(strict_val[target], strict_val["base_forecast"])))
        print("MAE:", mean_absolute_error(strict_val[target], strict_val["base_forecast"]))
        print("sMAPE:", smape(strict_val[target], strict_val["base_forecast"]))

    if not strict_test.empty:
        print("\nSTRICT TEST (strong series only)")
        print("RMSE:", np.sqrt(mean_squared_error(strict_test[target], strict_test["base_forecast"])))
        print("MAE:", mean_absolute_error(strict_test[target], strict_test["base_forecast"]))
        print("sMAPE:", smape(strict_test[target], strict_test["base_forecast"]))


# In[37]:


print("Prediction summary")
print("Actual mean   :", float(test_scored[target].mean()))
print("Predicted mean:", float(test_scored["base_forecast"].mean()))


# In[38]:


def evaluate_hierarchy_levels(scored_df, actual_col="actual", pred_col="forecast", save_path=None):
    levels = {
        "sku_store": ["product_code", "store_code"],
        "store": ["store_code"],
        "cluster": ["nestle_store_cluster"],
        "region": ["nestle_region"],
        "national": [],
    }

    rows = []

    for level_name, level_cols in levels.items():
        group_cols = level_cols + ["ds"] if level_cols else ["ds"]

        agg_df = (
            scored_df
            .groupby(group_cols, observed=True)[[actual_col, pred_col]]
            .sum()
            .reset_index()
            .rename(columns={
                actual_col: "actual",
                pred_col: "forecast"
            })
        )

        rmse = float(np.sqrt(mean_squared_error(agg_df["actual"], agg_df["forecast"])))
        mae = float(mean_absolute_error(agg_df["actual"], agg_df["forecast"]))
        smape = float(
            np.mean(
                np.abs(agg_df["actual"] - agg_df["forecast"]) /
                np.maximum((np.abs(agg_df["actual"]) + np.abs(agg_df["forecast"])) / 2.0, 1.0)
            ) * 100.0
        )

        rows.append({
            "level": level_name,
            "rmse": rmse,
            "mae": mae,
            "smape": smape
        })

    metrics_df = pd.DataFrame(rows)

    if save_path is not None:
        metrics_df.to_csv(save_path, index=False)

    return metrics_df


# In[39]:


def format_metrics(df):
    out = df.copy()

    out["rmse"] = out["rmse"].map(lambda x: f"{x:,.2f}")
    out["mae"] = out["mae"].map(lambda x: f"{x:,.2f}")
    out["smape"] = out["smape"].map(lambda x: f"{x:.2f}")

    return out


# In[40]:


print(val_scored.columns.tolist())


# In[41]:


val_scored = val_scored.copy()
test_scored = test_scored.copy()

val_scored["actual"] = pd.to_numeric(val_scored["net_sales_ty"], errors="coerce").fillna(0.0)
test_scored["actual"] = pd.to_numeric(test_scored["net_sales_ty"], errors="coerce").fillna(0.0)

# final forecast = model forecast for eligible rows, fallback forecast otherwise
val_scored["forecast"] = np.where(
    val_scored["eligible_for_model"].eq(1),
    pd.to_numeric(val_scored["base_forecast"], errors="coerce"),
    pd.to_numeric(val_scored["fallback_forecast"], errors="coerce"),
)

test_scored["forecast"] = np.where(
    test_scored["eligible_for_model"].eq(1),
    pd.to_numeric(test_scored["base_forecast"], errors="coerce"),
    pd.to_numeric(test_scored["fallback_forecast"], errors="coerce"),
)

val_scored["forecast"] = pd.to_numeric(val_scored["forecast"], errors="coerce").fillna(0.0)
test_scored["forecast"] = pd.to_numeric(test_scored["forecast"], errors="coerce").fillna(0.0)


# In[42]:


val_hierarchy_metrics = evaluate_hierarchy_levels(val_scored)
test_hierarchy_metrics = evaluate_hierarchy_levels(test_scored)

print("Hierarchy forecast accuracy")
print("VALIDATION")
display(format_metrics(val_hierarchy_metrics))

print("\nTEST")
display(format_metrics(test_hierarchy_metrics))


# ## 10. Test Forecast Output

# In[43]:


# Build historical scored output for DB export
base_output_frames = []

for split_name, scored_df in [("val", val_scored), ("test", test_scored)]:
    if scored_df is None or scored_df.empty:
        continue

    keep_cols = [
        "ds",
        "split",
        "product_code",
        "store_code",
        "nestle_store_cluster",
        "nestle_region",
        "store_description",
        "product_description",
        "brand",
        "category",
        "net_sales_ty",
        "base_forecast",
        "fallback_forecast",
        "eligible_for_model",
        "eligible_for_strict_eval",
    ]
    keep_cols = [c for c in keep_cols if c in scored_df.columns]

    export_df = scored_df[keep_cols].copy()
    export_df["split"] = split_name
    export_df["actual"] = pd.to_numeric(scored_df["net_sales_ty"], errors="coerce").fillna(0.0).astype("float32")
    export_df["base_forecast"] = pd.to_numeric(scored_df["base_forecast"], errors="coerce").fillna(0.0).astype("float32")
    export_df["fallback_forecast"] = pd.to_numeric(scored_df["fallback_forecast"], errors="coerce").fillna(0.0).astype("float32")
    export_df["forecast"] = np.where(
        pd.to_numeric(scored_df["eligible_for_model"], errors="coerce").fillna(0).astype("int8") == 1,
        export_df["base_forecast"],
        export_df["fallback_forecast"],
    ).astype("float32")
    base_output_frames.append(export_df)

if base_output_frames:
    base_forecast_output = optimize_dataframe_memory(pd.concat(base_output_frames, ignore_index=True))
else:
    base_forecast_output = pd.DataFrame()

print("Base forecast export shape:", base_forecast_output.shape)
print("Base forecast export memory (MB):", round(base_forecast_output.memory_usage(deep=True).sum() / (1024 ** 2), 2))
display(base_forecast_output.head())

del base_output_frames
gc.collect()


# In[44]:


write_df_fast(base_forecast_output, "stg_lightgbm_base_forecasts")
print("Saved base forecasts to retail.stg_lightgbm_base_forecasts")
base_forecast_output.head()


# ## 11. Future Forecast Generation

# In[45]:


last_ds = panel_model["ds"].max()

future_dates = pd.date_range(
    start=last_ds + pd.offsets.MonthBegin(1),
    periods=3,
    freq="MS"
)

print("Future forecasting setup")
print("Last historical month:", last_ds)
print("Future dates:", future_dates)


# In[46]:


series_id_cols = ["product_code", "store_code"]

optional_meta_cols = [
    "nestle_store_cluster",
    "nestle_region",
    "store_description",
    "product_description",
    "brand",
    "category",
    "eligible_for_model",
    "history_months_train",
    "nonzero_months_train",
    "total_sales_train",
    "demand_share_train",
    "filled_gap_share_train",
    "eligible_for_strict_eval",
]

meta_cols = [c for c in optional_meta_cols if c in panel.columns]

panel_sorted = panel.sort_values(series_id_cols + ["ds"]).reset_index(drop=True).copy()
last_ds = panel_sorted["ds"].max()

latest_snapshot = (
    panel_sorted
    .loc[panel_sorted["ds"].eq(last_ds), series_id_cols + meta_cols + ["net_sales_ty"]]
    .drop_duplicates(subset=series_id_cols, keep="last")
    .reset_index(drop=True)
)

for col in ["net_sales_ty", "history_months_train", "nonzero_months_train", "total_sales_train"]:
    if col in latest_snapshot.columns:
        latest_snapshot[col] = pd.to_numeric(latest_snapshot[col], errors="coerce").fillna(0.0)

active_series_df = latest_snapshot[
    (
        latest_snapshot["net_sales_ty"].gt(0)
        | latest_snapshot.get("nonzero_months_train", pd.Series(0, index=latest_snapshot.index)).ge(3)
        | latest_snapshot.get("total_sales_train", pd.Series(0.0, index=latest_snapshot.index)).gt(0)
    )
].copy()

active_series_df = active_series_df.drop_duplicates(subset=series_id_cols).reset_index(drop=True)

print("Last observed month:", last_ds)
print("Historical last-month rows:", len(latest_snapshot))
print("Active series selected for forecast:", len(active_series_df))


# In[47]:


# Build compact 12-month state once, then update it vectorially across the forecast horizon.
panel_sorted["_seq"] = panel_sorted.groupby(series_id_cols, sort=False).cumcount() + 1
panel_sorted["_max_seq"] = panel_sorted.groupby(series_id_cols, sort=False)["_seq"].transform("max")
panel_sorted["_rev_rank"] = panel_sorted["_max_seq"] - panel_sorted["_seq"] + 1

hist_tail = panel_sorted.loc[
    panel_sorted["_rev_rank"] <= 12,
    series_id_cols + ["_rev_rank", "net_sales_ty"]
].copy()

state_wide = (
    hist_tail
    .pivot_table(
        index=series_id_cols,
        columns="_rev_rank",
        values="net_sales_ty",
        aggfunc="last",
    )
    .rename(columns=lambda x: f"h{int(x)}")
    .reset_index()
)

for i in range(1, 13):
    col = f"h{i}"
    if col not in state_wide.columns:
        state_wide[col] = np.nan

state_cols = [f"h{i}" for i in range(1, 13)]
state_wide[state_cols] = state_wide[state_cols].apply(pd.to_numeric, errors="coerce").astype("float32")

if "seasonal_lookup" not in globals():
    seasonal_lookup = pd.DataFrame(columns=["product_code", "month", "seasonal_multiplier"])

if "seasonal_month_maps" not in globals():
    seasonal_month_maps = {}

if "strength_map" not in globals():
    strength_map = pd.Series(dtype="float32")

if "latest_trend_map" not in globals():
    latest_trend_map = pd.Series(dtype="float32")

future_base = active_series_df.merge(state_wide, on=series_id_cols, how="left", validate="one_to_one")

for c in state_cols:
    if c not in future_base.columns:
        future_base[c] = np.nan
    future_base[c] = pd.to_numeric(future_base[c], errors="coerce").astype("float32")

print("Forecast base frame shape:", future_base.shape)

del hist_tail, state_wide
gc.collect()


# In[48]:


def rolling_stat_from_state(df, cols, min_periods, stat="mean"):
    arr = df[cols].to_numpy(dtype="float32")
    valid = np.isfinite(arr)
    counts = valid.sum(axis=1)

    if stat == "mean":
        out = np.nanmean(arr, axis=1)
    elif stat == "std":
        out = np.nanstd(arr, axis=1, ddof=0)
    else:
        raise ValueError(f"Unsupported stat: {stat}")

    out = np.where(counts >= min_periods, out, np.nan)
    return out.astype("float32")


# In[49]:


future_rows = []
state_df = future_base.copy()

for d in tqdm(future_dates, desc="Forecast horizon", unit="month"):
    temp = state_df[series_id_cols + meta_cols].copy()

    month_num = int(pd.Timestamp(d).month)
    quarter_num = int(pd.Timestamp(d).quarter)
    year_num = int(pd.Timestamp(d).year)

    temp["ds"] = pd.Timestamp(d)
    temp["month"] = month_num
    temp["quarter"] = quarter_num
    temp["year"] = year_num
    temp["month_sin"] = np.float32(np.sin(2 * np.pi * month_num / 12))
    temp["month_cos"] = np.float32(np.cos(2 * np.pi * month_num / 12))

    temp["lag_1"] = state_df["h1"]
    temp["lag_2"] = state_df["h2"]
    temp["lag_3"] = state_df["h3"]
    temp["lag_6"] = state_df["h6"]
    temp["lag_12"] = state_df["h12"]

    temp["rolling_mean_3"] = rolling_stat_from_state(state_df, ["h1", "h2", "h3"], min_periods=2, stat="mean")
    temp["rolling_mean_6"] = rolling_stat_from_state(state_df, ["h1", "h2", "h3", "h4", "h5", "h6"], min_periods=3, stat="mean")
    temp["rolling_mean_12"] = rolling_stat_from_state(state_df, [f"h{i}" for i in range(1, 13)], min_periods=6, stat="mean")

    temp["rolling_std_3"] = rolling_stat_from_state(state_df, ["h1", "h2", "h3"], min_periods=2, stat="std")
    temp["rolling_std_6"] = rolling_stat_from_state(state_df, ["h1", "h2", "h3", "h4", "h5", "h6"], min_periods=3, stat="std")
    temp["rolling_std_12"] = rolling_stat_from_state(state_df, [f"h{i}" for i in range(1, 13)], min_periods=6, stat="std")

    temp["trend_growth"] = (
        (temp["lag_1"] - temp["lag_2"]) /
        np.maximum(np.abs(temp["lag_2"]), 1.0)
    ).replace([np.inf, -np.inf], 0.0).fillna(0.0).astype("float32")

    temp["rolling_growth_3"] = (
        (temp["rolling_mean_3"] - temp["rolling_mean_6"]) /
        np.maximum(np.abs(temp["rolling_mean_6"]), 1.0)
    ).replace([np.inf, -np.inf], 0.0).fillna(0.0).astype("float32")

    month_series = seasonal_month_maps.get(month_num)
    if month_series is not None and len(month_series) > 0:
        temp["seasonal_multiplier"] = temp["product_code"].map(month_series).astype("float32")
    else:
        temp["seasonal_multiplier"] = np.float32(1.0)
    temp["seasonal_multiplier"] = pd.to_numeric(temp["seasonal_multiplier"], errors="coerce").fillna(1.0).astype("float32")

    temp["seasonal_strength"] = pd.to_numeric(temp["product_code"].map(strength_map), errors="coerce").fillna(0.0).astype("float32")
    temp["trend"] = pd.to_numeric(temp["product_code"].map(latest_trend_map), errors="coerce").fillna(0.0).astype("float32")
    temp["seasonal_baseline"] = (temp["trend"] * temp["seasonal_multiplier"]).astype("float32")
    temp["seasonal_lag_1"] = (temp["lag_1"] * temp["seasonal_multiplier"]).astype("float32")

    temp["is_observed_month"] = 0
    temp["is_gap_filled"] = 0

    fallback_matrix = np.column_stack([
        temp["lag_12"].to_numpy(dtype="float32"),
        temp["lag_1"].to_numpy(dtype="float32"),
        temp["rolling_mean_3"].to_numpy(dtype="float32"),
        temp["rolling_mean_6"].to_numpy(dtype="float32"),
    ])
    valid_mask = np.isfinite(fallback_matrix) & (fallback_matrix > 0)
    first_valid_idx = np.where(valid_mask.any(axis=1), valid_mask.argmax(axis=1), -1)
    fallback_vals = np.zeros(len(temp), dtype="float32")
    valid_rows = first_valid_idx >= 0
    fallback_vals[valid_rows] = fallback_matrix[np.arange(len(temp))[valid_rows], first_valid_idx[valid_rows]]
    temp["fallback_forecast"] = fallback_vals

    has_signal = (
        temp["lag_1"].gt(0).fillna(False)
        | temp["lag_12"].gt(0).fillna(False)
        | temp["rolling_mean_3"].gt(0).fillna(False)
        | temp["rolling_mean_6"].gt(0).fillna(False)
    )

    base_eligible = pd.to_numeric(temp.get("eligible_for_model", 0), errors="coerce").fillna(0).astype("int8")
    temp["eligible_for_model"] = ((base_eligible == 1) & has_signal).astype("int8")

    temp = preprocess_model_frame(temp, features, cat_cols)
    temp["forecast_net_sales_ty"] = temp["fallback_forecast"].fillna(0.0).astype("float32")

    eligible_mask = temp["eligible_for_model"].eq(1)
    if eligible_mask.any():
        pred_input = temp.loc[eligible_mask, features].copy()
        for c in cat_cols:
            if c in pred_input.columns:
                pred_input[c] = pred_input[c].astype("category")

        model_pred = np.maximum(model.predict(pred_input), 0.0).astype("float32")
        fallback_vals = temp.loc[eligible_mask, "fallback_forecast"].fillna(0.0).astype("float32")
        alpha = 0.5
        temp.loc[eligible_mask, "forecast_net_sales_ty"] = (
            alpha * model_pred + (1 - alpha) * fallback_vals
        ).astype("float32")
        del pred_input, model_pred, fallback_vals

    temp["forecast_net_sales_ty"] = (
        pd.to_numeric(temp["forecast_net_sales_ty"], errors="coerce")
        .fillna(0.0)
        .clip(lower=0.0)
        .astype("float32")
    )

    if pd.Timestamp(d) == pd.Timestamp(future_dates[0]):
        last_total = panel.loc[panel["ds"] == panel["ds"].max(), "net_sales_ty"].sum()
        forecast_total = temp["forecast_net_sales_ty"].sum()
        if forecast_total > 0:
            scale_factor = last_total / forecast_total
            temp["forecast_net_sales_ty"] = (temp["forecast_net_sales_ty"] * scale_factor).astype("float32")

    future_rows.append(temp)
    for i in range(12, 1, -1):
        state_df[f"h{i}"] = state_df[f"h{i-1}"].astype("float32")
    state_df["h1"] = temp["forecast_net_sales_ty"].to_numpy(dtype="float32")

future_df = pd.concat(future_rows, ignore_index=True)
future_df = optimize_dataframe_memory(future_df)

print("Future forecast shape:", future_df.shape)
print("Future forecast memory (MB):", round(future_df.memory_usage(deep=True).sum() / (1024 ** 2), 2))
display(future_df.head())

del future_rows, state_df
gc.collect()


# In[ ]:


pass


# In[ ]:


pass


# In[ ]:


pass


# In[ ]:


pass


# In[50]:


# Build and save future/dashboard forecast output
future_dashboard_output = future_df.copy()
future_dashboard_output["split"] = "forecast"
future_dashboard_output["base_forecast"] = pd.to_numeric(
    future_dashboard_output.get("forecast_net_sales_ty", 0.0), errors="coerce"
).fillna(0.0).astype("float32")

dashboard_keep_cols = [
    "ds",
    "split",
    "product_code",
    "store_code",
    "nestle_store_cluster",
    "nestle_region",
    "store_description",
    "product_description",
    "brand",
    "category",
    "eligible_for_model",
    "base_forecast",
    "fallback_forecast",
    "trend",
    "seasonal_multiplier",
    "seasonal_strength",
]
dashboard_keep_cols = [c for c in dashboard_keep_cols if c in future_dashboard_output.columns]
future_dashboard_output = optimize_dataframe_memory(future_dashboard_output[dashboard_keep_cols].copy())

write_df_fast(future_dashboard_output, "stg_lightgbm_dashboard_forecast")
print("Saved future dashboard forecasts to retail.stg_lightgbm_dashboard_forecast")
display(future_dashboard_output.head())

gc.collect()


# ## 12. Dashboard code removed for runtime-focused DB-only pipeline

# In[ ]:


pass


# In[ ]:


pass


# In[ ]:


pass


# In[ ]:


pass


# In[ ]:


pass


# In[ ]:


pass


# In[ ]:


pass


# ## 13. Frontend and Database Export

# In[51]:


# ============================================================
# FRONTEND EXPORT: FORECAST METRICS (adds MASE gap)
# ============================================================
steps = [
    "Compute metrics",
    "Write forecast metrics",
    "Write validation hierarchy metrics",
    "Write test hierarchy metrics",
    "Write forecast insights"
]

with tqdm(total=len(steps), desc="Saving outputs", unit="step") as pbar:

    # Step 1: compute metrics
    def mase(actual, forecast):
        actual = pd.Series(actual).astype(float).reset_index(drop=True)
        forecast = pd.Series(forecast).astype(float).reset_index(drop=True)
        naive_denom = actual.diff().abs().dropna().mean()
        if pd.isna(naive_denom) or naive_denom == 0:
            return np.nan
        return np.abs(actual - forecast).mean() / naive_denom

    forecast_metrics_df = pd.DataFrame([{
        "split": "test",
        "rmse": float(rmse_test),
        "mae": float(mae_test),
        "smape": float(smape_test),
        "mase": float(mase(test_scored[target], test_scored["base_forecast"])) if len(test_scored) > 1 else np.nan,
        "actual_mean": float(pd.to_numeric(test_scored[target], errors="coerce").mean()),
        "pred_mean": float(pd.to_numeric(test_scored["base_forecast"], errors="coerce").mean()),
    }])
    pbar.update(1)

    # Step 2: write forecast metrics
    write_df_fast(forecast_metrics_df, "stg_lightgbm_forecast_metrics")
    pbar.update(1)

    # Step 3: validation hierarchy
    if 'val_hierarchy_metrics' in globals():
        write_df_fast(
            standardize_columns(val_hierarchy_metrics.assign(split="val")),
            "stg_lightgbm_hierarchy_metrics_val"
        )
    pbar.update(1)

    # Step 4: test hierarchy
    if 'test_hierarchy_metrics' in globals():
        write_df_fast(
            standardize_columns(test_hierarchy_metrics.assign(split="test")),
            "stg_lightgbm_hierarchy_metrics_test"
        )
    pbar.update(1)

    # Step 5: insights
    forecast_insights_df = pd.DataFrame([
        {"sort_order": 1, "label": "Model Type", "value": "LightGBM global model"},
        {"sort_order": 2, "label": "Forecast Target", "value": "net_sales_ty"},
        {"sort_order": 3, "label": "Metrics", "value": "RMSE, MAE, sMAPE, MASE"},
        {"sort_order": 4, "label": "Fallback Logic", "value": "Uses fallback forecast when series is not eligible"},
        {"sort_order": 5, "label": "Frontend Source", "value": "Use with MinT reconciled outputs for dashboard charts"},
    ])
    write_df_fast(forecast_insights_df, "api_forecast_insights")
    pbar.update(1)


# In[52]:


# Verification
print('stg_lightgbm_forecast_metrics rows:', verify_table('stg_lightgbm_forecast_metrics'))
print('api_forecast_insights rows:', verify_table('api_forecast_insights'))


# In[ ]:





# In[53]:


hist_last = panel[panel["ds"] == panel["ds"].max()].copy()
future_first = future_df[future_df["ds"] == future_df["ds"].min()].copy()

print("Historical last-month rows:", len(hist_last))
print("Future first-month rows:", len(future_first))

print("Historical last-month total:", hist_last["net_sales_ty"].sum())
print("Future first-month total:", future_first["forecast_net_sales_ty"].sum())


# In[ ]:





# ## Added DB-only/frontend contract alignment

# In[54]:


# ============================================================
# EXTRA DB/API EXPORTS FOR FRONTEND CONTRACT
# ============================================================
# Exact frontend contract: api_forecast_metrics
forecast_metrics_api = standardize_columns(forecast_metrics_df.copy())
write_df_fast(forecast_metrics_api, "api_forecast_metrics")

print("stg_lightgbm_base_forecasts rows:", verify_table("stg_lightgbm_base_forecasts"))
print("stg_lightgbm_dashboard_forecast rows:", verify_table("stg_lightgbm_dashboard_forecast"))
print("api_forecast_metrics rows:", verify_table("api_forecast_metrics"))


# In[ ]:




