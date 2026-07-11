import { useState, useContext } from "react";
import { PageHeader, Panel, Field, Input, Switch, Checkbox, Spinner, useToast } from "@pk/ui";
import { useSettingsData, saveSettings } from "../api/settings";
import type { SettingsData } from "../api/settings";
import { ConfigContext } from "../ConfigContext";

type PatchFn = (updates: Partial<SettingsData>) => void;

export function Settings() {
  const config = useContext(ConfigContext);
  const { data: remote, loading, error } = useSettingsData(config);
  const [draft, setDraft] = useState<SettingsData | null>(null);
  const [saving, setSaving] = useState(false);
  const { toast } = useToast();
  const data = draft ?? remote;

  const patch: PatchFn = (updates) => {
    if (data == null) return;
    setDraft({ ...data, ...updates });
  };

  const handleSave = async () => {
    if (data == null) return;
    setSaving(true);
    try {
      await saveSettings(config, data);
      setDraft(null);
      toast({ title: "Saved", variant: "success" });
    } catch {
      toast({ title: "Save failed", variant: "danger" });
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <div className="page-body"><Spinner /></div>;
  if (error != null || data == null) return <div className="page-body"><p className="muted">{error ?? "Failed to load"}</p></div>;

  return (
    <>
      <PageHeader title="Settings" description="Plugin-wide preferences for consent behavior and geo detection."
        actions={<SettingsActions isDirty={draft != null} saving={saving} onDiscard={() => setDraft(null)} onSave={handleSave} />}
      />
      <div className="page-body stack stack--lg">
        <BehaviorPanel data={data} patch={patch} />
        <GeoPanel data={data} patch={patch} />
        <DangerPanel data={data} patch={patch} />
      </div>
    </>
  );
}

function SettingsActions({ isDirty, saving, onDiscard, onSave }: { isDirty: boolean; saving: boolean; onDiscard: () => void; onSave: () => void }) {
  return (
    <>
      <button className="btn btn--ghost" type="button" disabled={!isDirty} onClick={onDiscard}>Discard</button>
      <button className="btn btn--primary" type="button" disabled={saving} onClick={onSave}>{saving ? "Saving…" : "Save changes"}</button>
    </>
  );
}

function BehaviorPanel({ data, patch }: { data: SettingsData; patch: PatchFn }) {
  return (
    <Panel title="Consent behavior">
      <div className="row row--between">
        <div><div className="switch-row__label">Enable consent management</div><div className="field__hint" style={{ marginTop: "var(--sp-1)" }}>When off, no banner is shown and all scripts load unconditionally.</div></div>
        <Switch checked={data.consent_enabled} onCheckedChange={(v) => patch({ consent_enabled: v })} aria-label="Enable consent management" />
      </div>
      <hr />
      <div className="row row--between">
        <div><div className="switch-row__label">Google Consent Mode v2</div><div className="field__hint" style={{ marginTop: "var(--sp-1)" }}>Required if you run GA4 or Google Ads.</div></div>
        <Switch checked={data.consent_mode_v2} onCheckedChange={(v) => patch({ consent_mode_v2: v })} aria-label="Google Consent Mode v2" />
      </div>
      <hr />
      <div className="row row--between">
        <div><div className="switch-row__label">Floating "Cookie preferences" button</div><div className="field__hint" style={{ marginTop: "var(--sp-1)" }}>Adds a small persistent button so visitors can reopen the preference center.</div></div>
        <Switch checked={data.floating_reopen} onCheckedChange={(v) => patch({ floating_reopen: v })} aria-label="Floating cookie preferences button" />
      </div>
      <hr />
      <div className="row row--between">
        <div><div className="switch-row__label">Debug mode</div><div className="field__hint" style={{ marginTop: "var(--sp-1)" }}>Logs consent state changes and tag-blocker decisions to the browser console. Auto-disables on production if <code className="inline-code">WP_DEBUG</code> is false.</div></div>
        <Switch checked={data.debug_mode ?? false} onCheckedChange={(v) => patch({ debug_mode: v })} aria-label="Debug mode" />
      </div>
    </Panel>
  );
}

function GeoPanel({ data, patch }: { data: SettingsData; patch: PatchFn }) {
  return (
    <Panel title="Geo detection">
      <Field label="Geo header name" htmlFor="geo-header" description="The HTTP header your CDN sets with the visitor's ISO country code. Cloudflare uses CF-IPCountry.">
        <Input id="geo-header" value={data.geo_header} onChange={(e) => patch({ geo_header: e.target.value })} placeholder="e.g. CF-IPCountry" />
      </Field>
      <Field label="Geo state header" htmlFor="geo-state-header" description="HTTP header carrying the visitor's state/region code, e.g. CF-Region-Code on Cloudflare. Used to detect California for CCPA.">
        <Input id="geo-state-header" value={data.geo_state_header} onChange={(e) => patch({ geo_state_header: e.target.value })} placeholder="e.g. CF-Region-Code" />
      </Field>
      <Field label="IP-geo provider URL" htmlFor="geo-provider-url" description="Called with the visitor IP when no geo header is present. Must contain an {ip} placeholder. Leave blank to treat headerless visitors as Rest of World.">
        <Input id="geo-provider-url" value={data.geo_provider_url} onChange={(e) => patch({ geo_provider_url: e.target.value })} placeholder="https://api.example.com/geo?ip={ip}" />
      </Field>
      <Field label="Fallback region code" htmlFor="geo-fallback-region" description="Region code used when neither a geo header nor the IP provider resolves a country. e.g. ROW." className="field--narrow">
        <Input id="geo-fallback-region" value={data.geo_fallback_region} onChange={(e) => patch({ geo_fallback_region: e.target.value })} placeholder="e.g. ROW" />
      </Field>
      <Field label="Log retention (days)" htmlFor="retention-days" description="Consent records older than this are purged automatically. Minimum 30 days." className="field--narrow">
        <Input type="number" id="retention-days" value={String(data.log_retention_days)} onChange={(e) => patch({ log_retention_days: parseInt(e.target.value, 10) })} min="30" max="1825" />
      </Field>
    </Panel>
  );
}

type DangerPanelProps = { data: SettingsData; patch: PatchFn };

function DangerPanel({ data, patch }: DangerPanelProps) {
  return (
    <Panel title="Danger zone" description="These actions are destructive and cannot be undone." variant="danger">
      <div className="row row--between">
        <div>
          <div style={{ fontSize: "var(--fs-sm)", fontWeight: "var(--fw-medium)" }}>Remove all data on uninstall</div>
          <div className="field__hint" style={{ marginTop: "var(--sp-1)" }}>
            <Checkbox checked={data.uninstall_clean} onChange={(e) => patch({ uninstall_clean: e.target.checked })} label="When this plugin is deleted, also drop the consent log table, all registered scripts, all region rules, and all plugin options from the database." />
          </div>
        </div>
      </div>
    </Panel>
  );
}
