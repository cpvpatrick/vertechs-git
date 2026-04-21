from __future__ import annotations

from pathlib import Path
import traceback
import pandas as pd

_ORIGINAL_FILE = Path(__file__).resolve().parent / "original_sources" / "Finalize_APIs.py"


def _display_fallback(*objects, **kwargs):
    max_rows = kwargs.pop("max_rows", 10)
    for obj in objects:
        try:
            if isinstance(obj, pd.DataFrame):
                if obj.empty:
                    print("[display] Empty DataFrame")
                else:
                    print(obj.head(max_rows).to_string(index=False))
            elif isinstance(obj, pd.Series):
                if obj.empty:
                    print("[display] Empty Series")
                else:
                    print(obj.head(max_rows).to_string())
            else:
                print(obj)
        except Exception:
            print(obj)


def _clear_output_fallback(*args, **kwargs):
    return None


def _sanitize_source(text: str) -> str:
    sanitized_lines = []
    for line in text.splitlines():
        stripped = line.strip()

        # Drop notebook-only shell/IPython execution lines that break plain Python imports.
        if "get_ipython().system(" in stripped:
            continue
        if stripped.startswith("get_ipython().run_line_magic("):
            continue
        if stripped.startswith("get_ipython().run_cell_magic("):
            continue

        # Remove notebook/UI-only imports.
        if stripped == "from IPython.display import display":
            continue
        if stripped == "from IPython.display import display, clear_output":
            continue
        if stripped == "from IPython.display import clear_output":
            continue
        if stripped.startswith("from IPython.display import"):
            continue
        if stripped.startswith("import ipywidgets"):
            continue
        if stripped.startswith("from ipywidgets"):
            continue

        sanitized_lines.append(line)

    return "\n".join(sanitized_lines) + "\n"


def _execute_original(namespace: dict | None = None):
    ns = {
        "__name__": "__patched_pipeline_exec__",
        "__file__": str(_ORIGINAL_FILE),
        "display": _display_fallback,
        "clear_output": _clear_output_fallback,
    }
    if namespace:
        ns.update(namespace)

    source = _ORIGINAL_FILE.read_text(encoding="utf-8")
    source = _sanitize_source(source)
    code = compile(source, str(_ORIGINAL_FILE), "exec")
    exec(code, ns, ns)
    return ns


def run_finalize_apis(*args, **kwargs):
    print("=== RUNNING Finalize_APIs ===")
    try:
        ns = _execute_original()
        target = ns.get("run_finalize_apis")
        if callable(target):
            return target(*args, **kwargs)
        return ns
    except Exception:
        print("❌ Finalize_APIs failed")
        traceback.print_exc()
        raise


if __name__ == "__main__":
    run_finalize_apis()
