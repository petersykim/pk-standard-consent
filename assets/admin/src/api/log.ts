import { useState, useEffect } from "react";
import { apiFetch } from "./client";
import type { AppConfig } from "../config";

export type LogRow = {
  id: number;
  visitor_hash: string;
  region: string;
  preferences: string;
  analytics: string;
  marketing: string;
  policy_ver: string;
  consented_at: string;
  consent_method: string;
};
export type LogData = {
  rows: LogRow[];
  total: number;
  pages: number;
};
export type LogStats = {
  total: number;
  last30: number;
  acceptAllPct: number;
  rejectAllPct: number;
  customPct: number;
  retentionDays: number;
};

export function useLogData(
  config: AppConfig,
  page: number,
  perPage: number,
  region?: string,
  version?: string,
): {
  data: LogData | null;
  loading: boolean;
  error: string | null;
  reload: () => void;
} {
  const [data, setData] = useState<LogData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [refreshToken, setRefreshToken] = useState(0);

  useEffect(() => {
    setLoading(true);
    const params = new URLSearchParams({ page: String(page), per_page: String(perPage) });
    if (region != null && region !== "") params.set("region", region);
    if (version != null && version !== "") params.set("version", version);
    apiFetch<LogData>(config, "GET", `log?${params.toString()}`)
      .then(setData)
      .catch((e: unknown) => setError(e instanceof Error ? e.message : "Load failed"))
      .finally(() => setLoading(false));
  }, [config, page, perPage, region, version, refreshToken]);

  const reload = () => setRefreshToken((t) => t + 1);

  return { data, loading, error, reload };
}

export function useLogStats(config: AppConfig): {
  stats: LogStats | null;
  loading: boolean;
  error: string | null;
} {
  const [stats, setStats] = useState<LogStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiFetch<LogStats>(config, "GET", "log/stats")
      .then(setStats)
      .catch((e: unknown) => setError(e instanceof Error ? e.message : "Load failed"))
      .finally(() => setLoading(false));
  }, [config]);

  return { stats, loading, error };
}

export function eraseVisitor(config: AppConfig, id: number): Promise<{ deleted: number }> {
  return apiFetch(config, "DELETE", `log/visitor?id=${id}`);
}
