"""Command-line interface."""

from __future__ import annotations

import asyncio
import json
import sys
from pathlib import Path
from typing import Optional

import typer
from rich import box
from rich.console import Console
from rich.table import Table

from aixianlu_probe import __protocol_version__, __version__
from aixianlu_probe.config import load_config, write_example_config
from aixianlu_probe.engine import run_probes
from aixianlu_probe.geo import detect_location, merge_location
from aixianlu_probe.models import ProbeResult
from aixianlu_probe.reporter import build_payload, save_report, sign_report, upload_report
from aixianlu_probe.targets import DEFAULT_TARGETS

app = typer.Typer(
    name="aixianlu-probe",
    help="AIXianlu open-source probe for AI service connectivity.",
    add_completion=False,
    no_args_is_help=True,
)
# Piped output gets a fixed wide layout so columns are not truncated.
console = Console(width=None if sys.stdout.isatty() else 140)
err_console = Console(stderr=True)


def _print_table(results: list[ProbeResult]) -> None:
    table = Table(
        title="AIXianlu Probe Results",
        box=box.SIMPLE_HEAVY,
        show_lines=False,
    )
    table.add_column("Name", style="cyan", no_wrap=True)
    table.add_column("Model", style="dim", no_wrap=True)
    table.add_column("Reach", justify="center")
    table.add_column("OK", justify="center")
    table.add_column("Status", justify="right")
    table.add_column("TTFB", justify="right")
    table.add_column("TLS", style="dim", no_wrap=True)
    table.add_column("Error", style="dim")

    for r in results:
        reach = "[green]yes[/]" if r.reachable else "[red]no[/]"
        succ = "[green]yes[/]" if r.success else "[yellow]no[/]"
        status = str(r.status_code) if r.status_code is not None else "-"
        latency = f"{r.latency_ms:.0f} ms" if r.latency_ms is not None else "-"
        error = r.error_type or ""
        if r.error_class:
            error = f"{error}/{r.error_class}" if error else r.error_class
        if r.attempts > 1:
            error = f"{error} (x{r.attempts})".strip()
        table.add_row(
            r.name,
            r.model or "-",
            reach,
            succ,
            status,
            latency,
            r.tls_version or "-",
            error or "-",
        )

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
    isp: Optional[str] = typer.Option(None, "--isp", help="Override ISP label (电信/联通/移动)"),
    region: Optional[str] = typer.Option(None, "--region", help="Override province/city label"),
    asn: Optional[int] = typer.Option(None, "--asn", help="Override ASN"),
    auto_detect: bool = typer.Option(
        False, "--auto-detect-location", help="Detect ISP/region via a public IP info service"
    ),
) -> None:
    """Run connectivity probes against configured targets."""
    cfg = load_config(config)
    if isp:
        cfg.location.isp = isp
    if region:
        cfg.location.region = region
    if asn is not None:
        cfg.location.asn = asn

    if auto_detect or cfg.auto_detect_location:
        detected = asyncio.run(detect_location(cfg.geo_timeout_seconds))
        cfg.location = merge_location(cfg.location, detected)

    if not json_output:
        console.print(f"[bold]AIXianlu Probe[/] v{__version__} (protocol {__protocol_version__})")
        console.print(f"Probe ID : {cfg.probe_id}")
        console.print(f"Targets  : {len(cfg.targets)}")
        location = cfg.location
        console.print(
            f"Location : {location.isp or '未知'} / {location.region or '未知'}"
            + (f" / AS{location.asn}" if location.asn else "")
        )
        console.print()

    results = asyncio.run(run_probes(cfg))
    payload = build_payload(cfg, results)
    sign_report(cfg, payload)

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
    table.add_column("Model")
    table.add_column("Category")
    for t in DEFAULT_TARGETS:
        table.add_row(t.name, t.method, t.url, t.model or "-", t.category)
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


@app.command("id")
def show_id(
    config: Optional[Path] = typer.Option(None, "--config", "-c", help="Path to YAML config file"),
) -> None:
    """Print the stable probe ID derived from this machine."""
    cfg = load_config(config)
    console.print(cfg.probe_id)


@app.command()
def version() -> None:
    """Print version and exit."""
    console.print(f"aixianlu-probe {__version__} (protocol {__protocol_version__})")


if __name__ == "__main__":
    app()
