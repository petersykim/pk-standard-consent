import { useState, useEffect } from "react";
import { apiFetch } from "./client";
import type { AppConfig } from "../config";

export type RegionRule = {
  model: "opt-in" | "opt-out";
  preferences: boolean;
  analytics: boolean;
  marketing: boolean;
};
export type RegionsData = {
  groups: Record<string, RegionRule>;
  country_group: Record<string, string>;
  policy_version: string;
  reconsent_interval: number;
};

export function useRegionsData(config: AppConfig): {
  data: RegionsData | null;
  loading: boolean;
  error: string | null;
} {
  const [data, setData] = useState<RegionsData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiFetch<RegionsData>(config, "GET", "regions")
      .then(setData)
      .catch((e: unknown) => setError(e instanceof Error ? e.message : "Load failed"))
      .finally(() => setLoading(false));
  }, [config]);

  return { data, loading, error };
}

export function saveRegions(config: AppConfig, payload: Partial<RegionsData>): Promise<void> {
  return apiFetch(config, "PUT", "regions", payload).then(() => undefined);
}
