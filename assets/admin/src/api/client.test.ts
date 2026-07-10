import { describe, it, expect, vi, beforeEach } from "vitest";
import { apiFetch } from "./client";
import type { AppConfig } from "../config";

const config: AppConfig = {
  nonce: "test-nonce-123",
  restUrl: "https://example.com/wp-json/pk-standard-consent/v1/admin/",
  page: "pk-standard-consent",
};

beforeEach(() => {
  vi.resetAllMocks();
});

describe("apiFetch", () => {
  it("sends X-WP-Nonce header with the config nonce", async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ ok: true }),
    });
    vi.stubGlobal("fetch", mockFetch);

    await apiFetch(config, "GET", "settings");

    const [, init] = mockFetch.mock.calls[0] as [string, RequestInit];
    const headers = init.headers as Record<string, string>;
    expect(headers["X-WP-Nonce"]).toBe("test-nonce-123");
  });

  it("builds the URL from restUrl + path", async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({}),
    });
    vi.stubGlobal("fetch", mockFetch);

    await apiFetch(config, "GET", "categories");

    const [url] = mockFetch.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("https://example.com/wp-json/pk-standard-consent/v1/admin/categories");
  });

  it("throws on non-2xx response", async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: false,
      status: 403,
    });
    vi.stubGlobal("fetch", mockFetch);

    await expect(apiFetch(config, "GET", "settings")).rejects.toThrow("403");
  });

  it("sends JSON body on PUT", async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ saved: true }),
    });
    vi.stubGlobal("fetch", mockFetch);

    const body = { consent_enabled: true };
    await apiFetch(config, "PUT", "settings", body);

    const [, init] = mockFetch.mock.calls[0] as [string, RequestInit];
    expect(init.method).toBe("PUT");
    expect(init.body).toBe(JSON.stringify(body));
  });
});
