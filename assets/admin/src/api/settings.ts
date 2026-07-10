import { useState, useEffect } from "react";
import { apiFetch } from "./client";
import type { AppConfig } from "../config";

export type SettingsData = {
  consent_enabled: boolean;
  consent_mode_v2: boolean;
  floating_reopen: boolean;
  debug_mode: boolean;
  geo_header: string;
  geo_state_header: string;
  geo_provider_url: string;
  geo_fallback_region: string;
  log_retention_days: number;
  uninstall_clean: boolean;
};

export function useSettingsData(config: AppConfig): {
  data: SettingsData | null;
  loading: boolean;
  error: string | null;
} {
  const [data, setData] = useState<SettingsData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiFetch<SettingsData>(config, "GET", "settings")
      .then(setData)
      .catch((e: unknown) => setError(e instanceof Error ? e.message : "Load failed"))
      .finally(() => setLoading(false));
  }, [config]);

  return { data, loading, error };
}

export function saveSettings(config: AppConfig, payload: Partial<SettingsData>): Promise<void> {
  return apiFetch(config, "PUT", "settings", payload).then(() => undefined);
}
