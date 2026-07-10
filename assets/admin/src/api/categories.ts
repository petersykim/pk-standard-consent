import { useState, useEffect } from "react";
import { apiFetch } from "./client";
import type { AppConfig } from "../config";

export type Script = { handle: string; src: string; type: string };
export type Category = {
  slug: string;
  description: string;
  scripts: Script[];
};
export type CategoriesData = {
  bannerMessage: string;
  policyLink: string;
  categories: Category[];
};

export function useCategoriesData(config: AppConfig): {
  data: CategoriesData | null;
  loading: boolean;
  error: string | null;
} {
  const [data, setData] = useState<CategoriesData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiFetch<CategoriesData>(config, "GET", "categories")
      .then(setData)
      .catch((e: unknown) => setError(e instanceof Error ? e.message : "Load failed"))
      .finally(() => setLoading(false));
  }, [config]);

  return { data, loading, error };
}

export function saveCategories(config: AppConfig, payload: CategoriesData): Promise<void> {
  return apiFetch(config, "PUT", "categories", payload).then(() => undefined);
}
