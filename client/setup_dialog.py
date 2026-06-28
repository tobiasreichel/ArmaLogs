"""First-run configuration dialog for the Windows client."""
import json
import os
import socket
import tkinter as tk
from pathlib import Path
from tkinter import messagebox, ttk

from .config import default_config


DEFAULT_LOG_ROOT = Path.home() / "Documents/My Games/ArmaReforger/logs"


def run_setup_dialog() -> bool:
    """Show a simple setup dialog. Returns True if config was saved."""
    cfg = default_config()

    root = tk.Tk()
    root.title("ArmaLogs Client Setup")
    root.geometry("520x320")
    root.resizable(False, False)
    root.configure(bg="#151821")

    # Center window
    root.update_idletasks()
    x = (root.winfo_screenwidth() // 2) - (520 // 2)
    y = (root.winfo_screenheight() // 2) - (320 // 2)
    root.geometry(f"+{x}+{y}")

    style = ttk.Style(root)
    style.theme_use("clam")
    style.configure("TFrame", background="#151821")
    style.configure("TLabel", background="#151821", foreground="#d8dce4", font=("Segoe UI", 10))
    style.configure("TEntry", fieldbackground="#0f1115", foreground="#d8dce4", insertcolor="#d8dce4")
    style.configure("TButton", background="#4f8cff", foreground="#ffffff", font=("Segoe UI", 10))
    style.map("TButton", background=[("active", "#6fa3ff")])

    frame = ttk.Frame(root, padding=20)
    frame.pack(fill=tk.BOTH, expand=True)

    ttk.Label(frame, text="Server URL").grid(row=0, column=0, sticky=tk.W, pady=(0, 4))
    url_var = tk.StringVar(value=cfg.get("server_url", ""))
    url_entry = ttk.Entry(frame, textvariable=url_var, width=55)
    url_entry.grid(row=1, column=0, columnspan=2, sticky=tk.EW, pady=(0, 12))

    ttk.Label(frame, text="Friend Token").grid(row=2, column=0, sticky=tk.W, pady=(0, 4))
    token_var = tk.StringVar(value=cfg.get("token", ""))
    token_entry = ttk.Entry(frame, textvariable=token_var, width=55, show="•")
    token_entry.grid(row=3, column=0, columnspan=2, sticky=tk.EW, pady=(0, 12))

    ttk.Label(frame, text="Arma Reforger Logs Folder").grid(row=4, column=0, sticky=tk.W, pady=(0, 4))
    log_var = tk.StringVar(value=cfg.get("log_root", str(DEFAULT_LOG_ROOT)))
    log_entry = ttk.Entry(frame, textvariable=log_var, width=55)
    log_entry.grid(row=5, column=0, columnspan=2, sticky=tk.EW, pady=(0, 12))

    ttk.Label(frame, text="Scan Interval (seconds)").grid(row=6, column=0, sticky=tk.W, pady=(0, 4))
    interval_var = tk.StringVar(value=str(cfg.get("scan_interval_seconds", 30)))
    interval_entry = ttk.Entry(frame, textvariable=interval_var, width=10)
    interval_entry.grid(row=7, column=0, sticky=tk.W, pady=(0, 18))

    error_var = tk.StringVar()
    error_label = ttk.Label(frame, textvariable=error_var, foreground="#ff4f4f", wraplength=480)
    error_label.grid(row=8, column=0, columnspan=2, sticky=tk.EW, pady=(0, 10))

    def save():
        server_url = url_var.get().strip()
        token = token_var.get().strip()
        log_root = log_var.get().strip()
        interval = interval_var.get().strip()

        if not server_url or not token or not log_root:
            error_var.set("Server URL, token, and log folder are required.")
            return

        if not server_url.startswith(("http://", "https://")):
            server_url = "https://" + server_url

        if not server_url.endswith("/upload.php"):
            server_url = server_url.rstrip("/") + "/upload.php"

        try:
            interval_sec = max(10, int(interval))
        except ValueError:
            error_var.set("Scan interval must be a number of seconds (min 10).")
            return

        log_path = Path(log_root)
        if not log_path.is_dir():
            if not messagebox.askyesno(
                "Folder not found",
                f"The folder\n{log_path}\ndoes not exist yet.\n\nSave anyway and create it?",
            ):
                return
            log_path.mkdir(parents=True, exist_ok=True)

        cfg.set("server_url", server_url)
        cfg.set("token", token)
        cfg.set("log_root", str(log_path))
        cfg.set("scan_interval_seconds", interval_sec)

        root.destroy()

    def cancel():
        root.destroy()
        os._exit(0)

    btn_frame = ttk.Frame(frame)
    btn_frame.grid(row=9, column=0, columnspan=2, sticky=tk.E)
    ttk.Button(btn_frame, text="Save & Start", command=save).pack(side=tk.RIGHT, padx=(8, 0))
    ttk.Button(btn_frame, text="Cancel", command=cancel).pack(side=tk.RIGHT)

    frame.columnconfigure(0, weight=1)

    root.protocol("WM_DELETE_WINDOW", cancel)
    root.mainloop()

    return cfg.is_complete()
