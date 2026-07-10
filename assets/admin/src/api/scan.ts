import { useState, useEffect } from "react";
import { apiFetch } from "./client";
import type { AppConfig } from "../config";

export type ScanStatus = { status: "idle" | "running" | "done" | "partial" | "blocked"; count?: number; flagged?: number };
export type ScanRow = {
  cookie_name: string;
  provider: string;
  category: string;
  duration: string;
  source: string;
  first_seen: string;
  compliance_flag: number;
  category_override: string | null;
};

export function useScanStatus(config: AppConfig): {
  status: ScanStatus | null;
  loading: boolean;
} {
  const [status, setStatus] = useState<ScanStatus | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    apiFetch<ScanStatus>(config, "GET", "scan/status")
      .then(setStatus)
      .catch(() => setStatus({ status: "idle" }))
      .finally(() => setLoading(false));
  }, [config]);

  return { status, loading };
}

export function useScanResults(config: AppConfig): {
  data: ScanRow[] | null;
  loading: boolean;
  error: string | null;
} {
  const [data, setData] = useState<ScanRow[] | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiFetch<ScanRow[]>(config, "GET", "scan/results")
      .then(setData)
      .catch((e: unknown) => setError(e instanceof Error ? e.message : "Load failed"))
      .finally(() => setLoading(false));
  }, [config]);

  return { data, loading, error };
}

export function runScan(config: AppConfig): Promise<ScanStatus> {
  return apiFetch<ScanStatus>(config, "POST", "scan");
}

export function saveOverride(config: AppConfig, cookie_name: string, category: string): Promise<void> {
  return apiFetch(config, "PUT", "scan/override", { cookie_name, category }).then(() => undefined);
}

export type ScanConfig = {
  scan_urls: string;
  scan_url_limit: number;
  scan_request_timeout: number;
  scan_wall_limit: number;
  scan_sslverify: boolean;
};

export function useScanConfig(config: AppConfig): {
  data: ScanConfig | null;
  loading: boolean;
  error: string | null;
} {
  const [data, setData] = useState<ScanConfig | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiFetch<ScanConfig>(config, "GET", "scan/config")
      .then(setData)
      .catch((e: unknown) => setError(e instanceof Error ? e.message : "Load failed"))
      .finally(() => setLoading(false));
  }, [config]);

  return { data, loading, error };
}

export function saveScanConfig(config: AppConfig, payload: Partial<ScanConfig>): Promise<void> {
  return apiFetch(config, "PUT", "scan/config", payload).then(() => undefined);
}
