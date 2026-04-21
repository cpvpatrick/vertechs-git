import time
import numpy as np
import pandas as pd
from tqdm import tqdm
from sqlalchemy import text

# adjust this import based on your project
from db_config import engine, SCHEMA


def read_df(query, parse_dates=None):
    return pd.read_sql_query(text(query), engine, parse_dates=parse_dates)


def write_df(df, table_name):
    df.to_sql(
        table_name,
        engine,
        schema=SCHEMA,
        if_exists="replace",
        index=False,
        method="multi",
        chunksize=10000,
    )
    print(f"[ok] {SCHEMA}.{table_name}: {len(df):,} rows")


def table_exists(table_name):
    query = f"""
    SELECT EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = :schema
          AND table_name = :table_name
    ) AS exists_flag
    """
    with engine.connect() as conn:
        result = conn.execute(
            text(query),
            {"schema": SCHEMA, "table_name": table_name}
        ).scalar()
    return bool(result)


def get_table_count(table_name):
    query = f'SELECT COUNT(*) AS n FROM "{SCHEMA}"."{table_name}"'
    return int(read_df(query).iloc[0, 0])


def safe_get_table_count(table_name):
    if not table_exists(table_name):
        return {
            "table_name": table_name,
            "row_count": 0,
            "exists": False,
            "status": "missing",
        }

    try:
        n = get_table_count(table_name)
        return {
            "table_name": table_name,
            "row_count": int(n),
            "exists": True,
            "status": "ok" if n > 0 else "empty",
        }
    except Exception as e:
        return {
            "table_name": table_name,
            "row_count": 0,
            "exists": True,
            "status": f"error: {str(e)}",
        }


def backfill_next_3m_forecast():
    required = ["api_overview_kpis", "api_forecast_horizon"]

    missing = [t for t in required if not table_exists(t)]
    if missing:
        print(f"[skip] next_3m_forecast backfill skipped. Missing tables: {missing}")
        return None

    overview_kpis = read_df(
        f'SELECT * FROM "{SCHEMA}"."api_overview_kpis"',
        parse_dates=["as_of_month"]
    )
    forecast_horizon = read_df(
        f'SELECT * FROM "{SCHEMA}"."api_forecast_horizon"',
        parse_dates=["ds"]
    )

    if overview_kpis.empty:
        print("[skip] api_overview_kpis is empty; no backfill applied.")
        return None

    if forecast_horizon.empty:
        next_3m = np.nan
    else:
        forecast_horizon = forecast_horizon.sort_values("ds").head(3).copy()
        next_3m = (
            pd.to_numeric(
                forecast_horizon.get("reconciled_forecast"),
                errors="coerce"
            )
            .fillna(0)
            .sum()
        )

    overview_kpis["next_3m_forecast"] = float(next_3m) if pd.notna(next_3m) else np.nan
    write_df(overview_kpis, "api_overview_kpis")

    print(f"[ok] next_3m_forecast backfilled: {next_3m:,.2f}" if pd.notna(next_3m) else "[ok] next_3m_forecast backfilled as NaN")
    return float(next_3m) if pd.notna(next_3m) else np.nan


def build_contract_check():
    required_tables = [
        "api_overview_kpis",
        "api_overview_trend_chart",
        "api_overview_growth_drivers",
        "api_seasonality_monthly",
        "api_seasonality_insights",
        "api_trend_true_growth_table",
        "api_trend_true_growth_summary",
        "api_forecast_metrics",
        "api_forecast_chart",
        "api_forecast_horizon",
        "api_forecast_insights",
        "api_coherence_summary",
        "api_coherence_methodology",
        "api_coherence_failed_checks",
        "api_c2g_top_contributors",
        "api_c2g_drilldown",
        "api_prescription_actions",
        "api_prescription_implementation_guide",
    ]

    results = []
    for table_name in tqdm(required_tables, desc="Checking API tables", unit="table"):
        results.append(safe_get_table_count(table_name))

    contract_check = (
        pd.DataFrame(results)
        .sort_values(["status", "table_name"])
        .reset_index(drop=True)
    )

    return contract_check


def validate_critical_tables(contract_check):
    critical_tables = [
        "api_overview_kpis",
        "api_forecast_horizon",
        "api_forecast_chart",
        "api_prescription_actions",
    ]

    bad = contract_check[
        (contract_check["table_name"].isin(critical_tables)) &
        (contract_check["status"] != "ok")
    ].copy()

    if not bad.empty:
        raise ValueError(
            "Critical API tables failed validation:\n"
            + bad.to_string(index=False)
        )


def run_finalize_apis(return_contract_check=True):
    print("\n=== RUNNING FINALIZE APIS ===")
    start = time.time()

    print("\n[1/2] Backfilling cross-notebook API fields...")
    backfill_next_3m_forecast()

    print("\n[2/2] Running API contract check...")
    contract_check = build_contract_check()

    print("\n=== API CONTRACT CHECK ===")
    print(contract_check.to_string(index=False))

    try:
        validate_critical_tables(contract_check)
        print("\n✅ Critical API validation passed")
    except Exception as e:
        print(f"\n❌ Critical API validation failed\n{e}")
        raise

    elapsed = time.time() - start
    print(f"\n✅ Finalize APIs completed in {elapsed:.2f}s")

    if return_contract_check:
        return contract_check


if __name__ == "__main__":
    run_finalize_apis()