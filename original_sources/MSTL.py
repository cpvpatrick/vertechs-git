#!/usr/bin/env python
# coding: utf-8

# # 1. Imports
# Core libraries for MSTL decomposition, parquet exports, and PostgreSQL frontend writes.
# 

# In[11]:


import warnings
from pathlib import Path
import csv
from io import StringIO

import numpy as np
import pandas as pd
from statsmodels.tsa.seasonal import MSTL
from sqlalchemy import create_engine, text


# # 2. Database Helpers
# Connection settings plus reusable utilities for column standardization, safe percentages, verification, and fast PostgreSQL writes.
# 

# In[12]:


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

# ============================================================
# DATABASE CONFIGURATION
# ============================================================
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
    if cols:
        df = df.rename(columns=cols)
    return df

def optimize_dataframe_memory(df, category_threshold=0.35):
    if df is None or df.empty:
        return df

    for col in df.columns:
        series = df[col]
        if pd.api.types.is_integer_dtype(series):
            df[col] = pd.to_numeric(series, downcast="integer")
        elif pd.api.types.is_float_dtype(series):
            df[col] = pd.to_numeric(series, downcast="float")
        elif pd.api.types.is_object_dtype(series):
            non_null = series.dropna()
            if len(non_null) == 0:
                continue
            unique_ratio = non_null.nunique(dropna=True) / max(len(non_null), 1)
            if unique_ratio <= category_threshold:
                df[col] = series.astype("category")
    return df

def _normalize_chunk_for_sql(df):
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

def read_sql_fast(
    query,
    parse_dates=None,
    chunksize=25000,
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

    frames = []
    for chunk in tqdm(iterator, desc=f"Loading {label}", unit="chunk"):
        if optimize_memory:
            chunk = optimize_dataframe_memory(chunk)
        frames.append(chunk)

    if not frames:
        return pd.DataFrame()

    if len(frames) == 1:
        df = frames[0]
    else:
        df = pd.concat(frames, ignore_index=True, copy=False)

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

        first_chunk = _normalize_chunk_for_sql(df.iloc[: min(chunk_size, total_rows)].copy())
        first_chunk.head(0).to_sql(
            table_name,
            engine,
            schema=schema,
            if_exists="replace" if if_exists == "replace" else "append",
            index=False,
        )
        cols = ",".join([f'"{c}"' for c in first_chunk.columns])

        for start in tqdm(range(0, total_rows, chunk_size), desc=f"Writing {table_name}", unit="chunk"):
            chunk = _normalize_chunk_for_sql(df.iloc[start:start + chunk_size].copy())
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
            chunk = _normalize_chunk_for_sql(df.iloc[start:start + chunk_size].copy())
            chunk.to_sql(
                table_name,
                engine,
                schema=schema,
                if_exists=if_exists if start == 0 else "append",
                index=False,
                method="multi",
                chunksize=5000,
            )
            if_exists = "append"
            del chunk
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


# # 3. Paths and Output Configuration
# Defines the MSTL source panel and output parquet artifact locations.
# 

# In[13]:


# ============================================================
# INPUT / OUTPUT CONFIG (DB-ONLY)
# ============================================================
INPUT_TABLE = f'{SCHEMA}.stg_mstl_product_month'
OUTPUT_DECOMP_TABLE = "stg_mstl_decomposition_for_lightgbm"
OUTPUT_PROFILE_TABLE = "stg_mstl_seasonal_profiles"

print("INPUT_TABLE:", INPUT_TABLE)
print("OUTPUT_DECOMP_TABLE:", OUTPUT_DECOMP_TABLE)
print("OUTPUT_PROFILE_TABLE:", OUTPUT_PROFILE_TABLE)


# # 4. Load and Validate MSTL Input
# Loads the monthly product panel, enforces required schema, and standardizes the date and target columns.
# 

# In[14]:


panel_mstl = read_sql_fast(
    f"""
        SELECT
            "Product Code",
            DATE_TRUNC('month', ds)::date AS ds,
            SUM(y) AS y
        FROM {INPUT_TABLE}
        WHERE "Product Code" IS NOT NULL
          AND ds IS NOT NULL
        GROUP BY 1, 2
        ORDER BY 1, 2
    """,
    parse_dates=["ds"],
    label="panel_mstl",
)

required_cols = ["Product Code", "ds", "y"]
missing_cols = [col for col in required_cols if col not in panel_mstl.columns]
if missing_cols:
    raise KeyError(f"MSTL panel missing required columns: {missing_cols}")

panel_mstl["Product Code"] = panel_mstl["Product Code"].astype(str).str.zfill(9)
panel_mstl["ds"] = pd.to_datetime(panel_mstl["ds"], errors="coerce").dt.to_period("M").dt.to_timestamp(how="start")
panel_mstl["y"] = pd.to_numeric(panel_mstl["y"], errors="coerce").fillna(0.0).astype("float32")

panel_mstl = (
    panel_mstl[required_cols]
    .dropna(subset=["ds"])
    .sort_values(["Product Code", "ds"])
    .reset_index(drop=True)
)
panel_mstl = optimize_dataframe_memory(panel_mstl)

print("Rows:", len(panel_mstl))
print("Products:", panel_mstl["Product Code"].nunique())
print("Date range:", panel_mstl["ds"].min(), "to", panel_mstl["ds"].max())
print("Approx memory (MB):", round(panel_mstl.memory_usage(deep=True).sum() / 1024**2, 2))
display(panel_mstl.head())


# # 5. Run MSTL Decomposition
# Reindexes each product to a full monthly sequence, applies sparsity/quality filters, fits MSTL on log-transformed sales, and stores decomposition outputs.
# 

# In[15]:


results_batches = []
current_batch = []
failed_products = []

PERIOD = 12
BATCH_PRODUCTS = 250

grouped_products = panel_mstl.groupby("Product Code", sort=False, observed=True)
total_products = panel_mstl["Product Code"].nunique()

for idx, (product_code, product_df) in enumerate(
    tqdm(grouped_products, total=total_products, desc="Decomposing products", unit="product"),
    start=1,
):
    product_df = product_df[["ds", "y"]].copy()

    # A. Reindex to a full monthly range for the product
    full_ds = pd.date_range(product_df["ds"].min(), product_df["ds"].max(), freq="MS")
    product_df = (
        product_df.set_index("ds")
        .reindex(full_ds)
        .rename_axis("ds")
        .reset_index()
    )
    product_df["Product Code"] = str(product_code).zfill(9)
    product_df["y"] = product_df["y"].fillna(0.0).astype("float64")

    sales_values = product_df["y"].to_numpy(dtype="float64")

    # B. Basic eligibility checks
    if np.all(sales_values == 0):
        failed_products.append((product_code, "all_zero"))
        continue

    if len(sales_values) < (2 * PERIOD + 1):
        failed_products.append((product_code, f"too_short_after_reindex: n={len(sales_values)}"))
        continue

    nonzero_months = int((sales_values > 0).sum())
    demand_share = float((sales_values > 0).mean())
    unique_values = int(np.unique(sales_values).size)
    sales_std = float(np.std(sales_values))

    if nonzero_months < 6:
        failed_products.append((product_code, f"too_sparse_nonzero_months={nonzero_months}"))
        continue

    if demand_share < 0.25:
        failed_products.append((product_code, f"too_sparse_demand_share={demand_share:.2f}"))
        continue

    if unique_values < 4:
        failed_products.append((product_code, f"too_few_unique_values={unique_values}"))
        continue

    if sales_std == 0:
        failed_products.append((product_code, "zero_variance"))
        continue

    # C. Clip extreme values before decomposition
    upper_bound = np.percentile(sales_values, 99.5)
    clipped_sales = np.clip(sales_values, 0.0, upper_bound)

    # D. Fit MSTL on log1p scale
    log_sales = pd.Series(
        np.log1p(clipped_sales),
        index=pd.DatetimeIndex(product_df["ds"]),
        dtype="float64",
    )

    try:
        with warnings.catch_warnings():
            warnings.simplefilter("ignore")
            mstl_fit = MSTL(log_sales, periods=PERIOD).fit()

        trend_log = np.asarray(mstl_fit.trend, dtype="float64")
        seasonal_log = np.asarray(mstl_fit.seasonal, dtype="float64")
        resid_log = np.asarray(mstl_fit.resid, dtype="float64")

        if seasonal_log.ndim == 2:
            seasonal_log = seasonal_log[:, 0]

        # E. Back-transform components to interpretable scale
        trend = np.maximum(np.expm1(trend_log), 0.0)
        seasonal_multiplier = np.exp(seasonal_log)
        resid_multiplier = np.exp(resid_log)
        reconstructed = np.maximum(np.expm1(trend_log + seasonal_log + resid_log), 0.0)

        # F. Build per-product decomposition output
        product_decomp = pd.DataFrame({
            "Product Code": product_df["Product Code"].values,
            "ds": product_df["ds"].values,
            "month": product_df["ds"].dt.month.values,
            "y": sales_values.astype("float32"),
            "y_clip": clipped_sales.astype("float32"),
            "trend_log": trend_log.astype("float32"),
            "seasonal_log": seasonal_log.astype("float32"),
            "resid_log": resid_log.astype("float32"),
            "trend": trend.astype("float32"),
            "seasonal_multiplier": seasonal_multiplier.astype("float32"),
            "resid_multiplier": resid_multiplier.astype("float32"),
            "reconstructed": reconstructed.astype("float32"),
            "decomp_method": "MSTL_log1p_consistent",
        })

        current_batch.append(product_decomp)

        if (idx % BATCH_PRODUCTS == 0) or (idx == total_products):
            if current_batch:
                batch_df = pd.concat(current_batch, ignore_index=True, copy=False)
                batch_df = optimize_dataframe_memory(batch_df)
                results_batches.append(batch_df)
                current_batch = []
                gc.collect()

    except Exception as exc:
        failed_products.append((product_code, str(exc)))

del panel_mstl
gc.collect()

if not results_batches:
    raise ValueError("No products were successfully decomposed. Check MSTL input coverage and sparsity filters.")

panel_decomposed = pd.concat(results_batches, ignore_index=True, copy=False)
del results_batches, current_batch
gc.collect()

panel_decomposed["Product Code"] = panel_decomposed["Product Code"].astype(str).str.zfill(9)
panel_decomposed["ds"] = pd.to_datetime(panel_decomposed["ds"], errors="coerce")
panel_decomposed["month"] = pd.to_numeric(panel_decomposed["month"], errors="coerce").astype("Int16")
panel_decomposed = optimize_dataframe_memory(panel_decomposed)

failed_products_df = pd.DataFrame(failed_products, columns=["Product Code", "reason"])

print("Decomposed rows:", len(panel_decomposed))
print("Decomposed products:", panel_decomposed["Product Code"].nunique())
print("Failed products:", len(failed_products_df))
print("Approx decomposed memory (MB):", round(panel_decomposed.memory_usage(deep=True).sum() / 1024**2, 2))
if not failed_products_df.empty:
    display(failed_products_df.head(20))
display(panel_decomposed.head())


# # 6. Build Seasonal Features
# Computes per-product seasonal strength and normalized seasonal profiles for later forecasting and frontend use.
# 

# In[16]:


panel_decomposed["seasonal_plus_resid_log"] = (
    panel_decomposed["seasonal_log"].astype("float32")
    + panel_decomposed["resid_log"].astype("float32")
)

seasonal_strength_df = (
    panel_decomposed.groupby("Product Code", as_index=False, observed=True)
    .agg(
        resid_var=("resid_log", "var"),
        seasonal_plus_resid_var=("seasonal_plus_resid_log", "var"),
    )
)

seasonal_strength_df["seasonal_strength"] = (
    1.0 - (
        seasonal_strength_df["resid_var"].fillna(0.0)
        / seasonal_strength_df["seasonal_plus_resid_var"].replace(0, np.nan)
    )
).replace([np.inf, -np.inf], np.nan).fillna(0.0).clip(0.0, 1.0).astype("float32")

seasonal_strength_df = seasonal_strength_df[["Product Code", "seasonal_strength"]]
seasonal_strength_df["Product Code"] = seasonal_strength_df["Product Code"].astype(str).str.zfill(9)

panel_decomposed = panel_decomposed.merge(
    seasonal_strength_df,
    on="Product Code",
    how="left",
    validate="many_to_one",
)

panel_decomposed["seasonal_strength"] = (
    pd.to_numeric(panel_decomposed["seasonal_strength"], errors="coerce")
    .fillna(0.0)
    .clip(0.0, 1.0)
    .astype("float32")
)

seasonal_profiles = (
    panel_decomposed
    .groupby(["Product Code", "month"], as_index=False, observed=True)
    .agg(seasonal_multiplier=("seasonal_multiplier", "mean"))
)

seasonal_profiles["Product Code"] = seasonal_profiles["Product Code"].astype(str).str.zfill(9)
seasonal_profiles["month"] = pd.to_numeric(seasonal_profiles["month"], errors="coerce").astype("Int16")

product_month_mean = seasonal_profiles.groupby("Product Code", observed=True)["seasonal_multiplier"].transform("mean")
seasonal_profiles["seasonal_multiplier"] = np.where(
    np.isfinite(product_month_mean) & (product_month_mean != 0),
    seasonal_profiles["seasonal_multiplier"] / product_month_mean,
    1.0,
).astype("float32")

mstl_export_for_lgbm = panel_decomposed[
    [
        "ds",
        "Product Code",
        "trend",
        "seasonal_multiplier",
        "seasonal_strength",
        "decomp_method",
    ]
].copy()
mstl_export_for_lgbm = optimize_dataframe_memory(mstl_export_for_lgbm)


print("seasonal_profiles contract columns:", list(seasonal_profiles.columns))
print("Unique months in seasonal_profiles:", sorted(pd.Series(seasonal_profiles["month"]).dropna().astype(int).unique().tolist())[:12])

display(seasonal_strength_df.head())
display(seasonal_profiles.head())


# # 7. Build and Save Parquet Outputs
# Creates the LightGBM-ready MSTL export, validates required fields, and writes parquet artifacts.
# 

# In[17]:


print("Skipped local parquet exports. DB tables are now the official downstream handoff.")
print("Prepared MSTL decomposition and seasonal profiles for DB export.")


# # 8. Prepare Frontend Export Tables
# Builds trend-true and seasonality tables separately from the database write step so transformations are easier to inspect and rerun.
# 

# In[18]:


mstl_export_for_lgbm = standardize_columns(mstl_export_for_lgbm)
seasonal_profiles = standardize_columns(seasonal_profiles)

if "ds" in mstl_export_for_lgbm.columns:
    mstl_export_for_lgbm["ds"] = pd.to_datetime(mstl_export_for_lgbm["ds"], errors="coerce")

if "month" in seasonal_profiles.columns:
    seasonal_profiles["month"] = pd.to_numeric(seasonal_profiles["month"], errors="coerce").astype("Int16")

trend_true_monthly = (
    panel_decomposed.groupby("ds", as_index=False, observed=True)
    .agg(
        raw_sales=("y", "sum"),
        trend_true_sales=("trend", "sum"),
        avg_seasonal_multiplier=("seasonal_multiplier", "mean"),
        avg_seasonal_strength=("seasonal_strength", "mean"),
    )
    .sort_values("ds")
)
trend_true_monthly = optimize_dataframe_memory(trend_true_monthly)

seasonality_monthly = (
    seasonal_profiles.groupby("month", as_index=False, observed=True)
    .agg(seasonal_index=("seasonal_multiplier", "mean"))
    .rename(columns={"month": "month_of_year"})
    .sort_values("month_of_year")
)
seasonality_monthly["month_name"] = pd.to_datetime(
    seasonality_monthly["month_of_year"], format="%m"
).dt.strftime("%b")
seasonality_monthly["vs_baseline_pct"] = safe_pct(
    seasonality_monthly["seasonal_index"] - 1.0,
    1.0,
    scale=100.0,
)
seasonality_monthly["notes"] = seasonality_monthly["month_of_year"].map({
    1: "Jan drop",
    9: "BER uplift",
    10: "BER uplift",
    11: "BER uplift",
    12: "Dec spike",
}).fillna("")
seasonality_monthly = optimize_dataframe_memory(seasonality_monthly)

seasonality_insights = pd.DataFrame([
    {"sort_order": 1, "title": "BER Months", "description": "Seasonality usually lifts from September to December."},
    {"sort_order": 2, "title": "December Peak", "description": "December often shows the highest seasonal index."},
    {"sort_order": 3, "title": "January Drop", "description": "January often softens after the holiday peak."},
])

display(trend_true_monthly.head())
display(seasonality_monthly.head())


# # 9. Write Frontend Tables to PostgreSQL
# Loads the prepared MSTL, trend, and seasonality outputs into PostgreSQL for frontend consumption.
# 

# In[19]:


write_df_fast(mstl_export_for_lgbm, OUTPUT_DECOMP_TABLE)
# Keep `month` in the MSTL seasonal profile export for LightGBM month-based lookups
write_df_fast(seasonal_profiles, OUTPUT_PROFILE_TABLE)

# Exact frontend contract: overview trend chart
trend_true_monthly = trend_true_monthly.sort_values("ds").reset_index(drop=True)
trend_true_monthly["mom_growth_pct"] = safe_pct(
    trend_true_monthly["trend_true_sales"].diff(),
    trend_true_monthly["trend_true_sales"].shift(1),
    scale=100.0,
)
trend_true_monthly["ytd_trend_true_sales"] = trend_true_monthly["trend_true_sales"].cumsum()
trend_true_monthly = optimize_dataframe_memory(trend_true_monthly)
write_df_fast(trend_true_monthly, "api_overview_trend_chart")

# Existing seasonality APIs
write_df_fast(seasonality_monthly, "api_seasonality_monthly")
write_df_fast(seasonality_insights, "api_seasonality_insights")

# Overview KPIs from latest actual/trend plus next 3M forecast if available later
prev_12 = trend_true_monthly["trend_true_sales"].shift(12).tail(12).sum() if len(trend_true_monthly) >= 13 else np.nan
curr_12 = trend_true_monthly["trend_true_sales"].tail(12).sum() if len(trend_true_monthly) >= 12 else np.nan
ytd_growth_pct = ((curr_12 - prev_12) / prev_12 * 100.0) if pd.notna(prev_12) and prev_12 != 0 else np.nan

overview_kpis = pd.DataFrame([{
    "as_of_month": trend_true_monthly["ds"].max(),
    "total_sales": float(trend_true_monthly.loc[trend_true_monthly["ds"].eq(trend_true_monthly["ds"].max()), "raw_sales"].sum()),
    "mom_growth_pct": float(trend_true_monthly["mom_growth_pct"].iloc[-1]) if len(trend_true_monthly) else np.nan,
    "ytd_growth_pct": float(ytd_growth_pct) if pd.notna(ytd_growth_pct) else np.nan,
    "next_3m_forecast": np.nan,
    "source_note": "next_3m_forecast is backfilled by the final runner after MinT finishes."
}])
write_df_fast(overview_kpis, "api_overview_kpis")

# Trend-true growth table (frontend-ready detailed table)
trend_true_growth_table = panel_decomposed.copy()

if "Product Description" not in trend_true_growth_table.columns:
    trend_true_growth_table["Product Description"] = pd.NA
if "Store Code" not in trend_true_growth_table.columns:
    trend_true_growth_table["Store Code"] = pd.NA
if "Store Description" not in trend_true_growth_table.columns:
    trend_true_growth_table["Store Description"] = pd.NA
if "NESTLE STORE CLUSTER" not in trend_true_growth_table.columns:
    trend_true_growth_table["NESTLE STORE CLUSTER"] = pd.NA
if "NESTLE REGION" not in trend_true_growth_table.columns:
    trend_true_growth_table["NESTLE REGION"] = pd.NA

trend_true_growth_table = trend_true_growth_table.rename(
    columns={
        "y": "raw_sales",
        "trend": "trend_true_sales",
    }
)

trend_true_growth_table["raw_vs_trend_diff"] = (
    pd.to_numeric(trend_true_growth_table["raw_sales"], errors="coerce").fillna(0.0)
    - pd.to_numeric(trend_true_growth_table["trend_true_sales"], errors="coerce").fillna(0.0)
)

trend_true_growth_table = trend_true_growth_table.sort_values(
    ["Product Code", "Store Code", "ds"]
).reset_index(drop=True)

group_keys = ["Product Code", "Store Code"]

trend_true_growth_table["raw_mom_growth_pct"] = (
    trend_true_growth_table.groupby(group_keys, dropna=False, observed=True)["raw_sales"]
    .pct_change() * 100.0
)
trend_true_growth_table["trend_mom_growth_pct"] = (
    trend_true_growth_table.groupby(group_keys, dropna=False, observed=True)["trend_true_sales"]
    .pct_change() * 100.0
)
trend_true_growth_table["raw_yoy_growth_pct"] = (
    trend_true_growth_table.groupby(group_keys, dropna=False, observed=True)["raw_sales"]
    .pct_change(12) * 100.0
)
trend_true_growth_table["trend_yoy_growth_pct"] = (
    trend_true_growth_table.groupby(group_keys, dropna=False, observed=True)["trend_true_sales"]
    .pct_change(12) * 100.0
)

trend_true_growth_table["abs_gap_pct"] = safe_pct(
    trend_true_growth_table["raw_vs_trend_diff"].abs(),
    trend_true_growth_table["raw_sales"].replace(0, np.nan),
    scale=100.0,
)

latest_ds = trend_true_growth_table["ds"].max() if len(trend_true_growth_table) else pd.NaT
trend_true_growth_table["is_latest_month"] = trend_true_growth_table["ds"].eq(latest_ds)

trend_true_growth_table = trend_true_growth_table[
    [
        "ds",
        "Product Code",
        "Product Description",
        "Store Code",
        "Store Description",
        "NESTLE STORE CLUSTER",
        "NESTLE REGION",
        "raw_sales",
        "trend_true_sales",
        "seasonal_multiplier",
        "seasonal_strength",
        "raw_vs_trend_diff",
        "raw_mom_growth_pct",
        "trend_mom_growth_pct",
        "raw_yoy_growth_pct",
        "trend_yoy_growth_pct",
        "abs_gap_pct",
        "is_latest_month",
    ]
].copy()

trend_true_growth_table = optimize_dataframe_memory(trend_true_growth_table)
write_df_fast(trend_true_growth_table, "api_trend_true_growth_table")

trend_true_growth_summary = pd.DataFrame([
    {
        "as_of_month": trend_true_monthly["ds"].max(),
        "latest_raw_sales": float(trend_true_monthly["raw_sales"].iloc[-1]),
        "latest_trend_true_sales": float(trend_true_monthly["trend_true_sales"].iloc[-1]),
        "avg_seasonal_strength": float(trend_true_monthly["avg_seasonal_strength"].iloc[-1]),
        "top_performers_note": "Use api_trend_true_growth_table with is_latest_month = true for ranking, filtering, and distribution."
    }
]) if len(trend_true_monthly) else pd.DataFrame(
    columns=[
        "as_of_month",
        "latest_raw_sales",
        "latest_trend_true_sales",
        "avg_seasonal_strength",
        "top_performers_note",
    ]
)
write_df_fast(trend_true_growth_summary, "api_trend_true_growth_summary")

# Release large intermediates after exports
del trend_true_growth_table, panel_decomposed
gc.collect()


# # 10. Final QA Summary
# Consolidated validation for MSTL inputs, decomposition outputs, parquet artifacts, and PostgreSQL frontend tables.
# 

# In[20]:


print("api_overview_trend_chart rows:", verify_table("api_overview_trend_chart"))
print("api_overview_kpis rows:", verify_table("api_overview_kpis"))
print("api_trend_true_growth_table rows:", verify_table("api_trend_true_growth_table"))
print("api_trend_true_growth_summary rows:", verify_table("api_trend_true_growth_summary"))

