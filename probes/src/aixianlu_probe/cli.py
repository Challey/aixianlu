"""Command-line interface."""

from __future__ import annotations

import asyncio
import json
import sys
from pathlib import Path
from typing import Optional

import typer
from rich.console import Console
from rich.table import Table
from rich import box

from aixianlu_probe import __version__
from aixianlu_probe.config import load_config, write_example_config
from aixianlu_probe.engine import run_probes
from aixianlu_probe.models import ProbeResult
from aixianlu_probe.reporter import build_payload, save_report, upload_report
from aixianlu_probe.targets import DEFAULT_TARGETS

app = typer.Typer(
    name="aixianlu-probe",
    help="AIXianlu open-source probe for AI service connectivity.",
    add_completion=False,
    no_args_is_help=True,
)
console = Console()
err_console = Console(stderr=True)


def _print_table(results: list[ProbeResult]) -> None:
    table = Table(
        title="AIXianlu Probe Results",
        box=box.SIMPLE_HEAVY,
        show_lines=False,
    )
    table.add_column("Name", style="cyan", no_wrap=True)
    table.add_column("Reachable", justify="center")
    table.add_column("Success", justify="center")
    table.add_column("Status", justify="right")
    table.add_column("Latency", justify="right")
    table.add_column("Error", style="dim")

    for r in results:
        reach = "[green]yes[/]" if r.reachable else "[red]no[/]"
        succ = "[green]yes[/]" if r.success else "[yellow]no[/]"
        status = str(r.status_code) if r.status_code is not None else "-"
        latency = f"{r.latency_ms:.0f} ms" if r.latency_ms is not None else "-"
        err = r.error_type or ""
        if r.error_message and r.error_type:
            err = f"{r.error_type}"
        table.add_row(r.name, reach, succ, status, latency, err)

    console.print(table)


@app.command()
def run(
    config: Optional[Path] = typer.Option(
        None, "--config", "-c", help="Path to YAML config file"
    ),
    json_output: bool = typer.Option(False, "--json", help="Print raw JSON only"),
    output: Optional[Path] = typer.Option(
        None, "--output", "-o", help="Save full report JSON to file"
    ),
    upload: bool = typer.Option(False, "--upload", help="Attempt to upload report"),
) -> None:
    """Run connectivity probes against configured targets."""
    cfg = load_config(config)

    if not json_output:
        console.print(f"[bold]AIXianlu Probe[/] v{__version__}")
        console.print(f"Probe ID : {cfg.probe_id}")
        console.print(f"Targets  : {len(cfg.targets)}")
        console.print()

    results = asyncio.run(run_probes(cfg))
    payload = build_payload(cfg, results)

    if json_output:
        print(json.dumps(payload.to_report_dict(), ensure_ascii=False, indent=2))
    else:
        _print_table(results)
        ok_count = sum(1 for r in results if r.success)
        reach_count = sum(1 for r in results if r.reachable)
        console.print()
        console.print(
            f"Summary: {reach_count}/{len(results)} reachable, "
            f"{ok_count}/{len(results)} success"
        )

    if output:
        save_report(payload, output)
        if not json_output:
            console.print(f"Report saved → {output}")

    if upload or cfg.report_enabled:
        ok, msg = asyncio.run(upload_report(cfg, payload))
        if not json_output:
            style = "green" if ok else "yellow"
            console.print(f"[{style}]Upload: {msg}[/]")


@app.command("targets")
def list_targets() -> None:
    """Show default built-in targets."""
    table = Table(title="Default Targets", box=box.SIMPLE)
    table.add_column("Name", style="cyan")
    table.add_column("Method")
    table.add_column("URL")
    table.add_column("Category")
    for t in DEFAULT_TARGETS:
        table.add_row(t.name, t.method, t.url, t.category)
    console.print(table)


@app.command("init-config")
def init_config(
    path: Path = typer.Argument(Path("config.yaml"), help="Where to write the example config"),
) -> None:
    """Write an example configuration file."""
    if path.exists():
        err_console.print(f"[red]File already exists: {path}[/]")
        raise typer.Exit(1)
    write_example_config(path)
    console.print(f"Example config written → {path}")


@app.command()
def version() -> None:
    """Print version and exit."""
    console.print(f"aixianlu-probe {__version__}")


if __name__ == "__main__":
    app()
