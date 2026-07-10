import { useState, useContext } from "react";
import { PageHeader, Panel, Field, Input, Callout, Spinner, useToast } from "@pk/ui";
import { useRegionsData, saveRegions } from "../api/regions";
import type { RegionsData, RegionRule } from "../api/regions";
import { RegionsTable } from "../components/RegionsTable";
import { CountryGroupEditor } from "../components/CountryGroupEditor";
import { ConfigContext } from "../ConfigContext";

export function RegionsRules() {
  const config = useContext(ConfigContext);
  const { data: remote, loading, error } = useRegionsData(config);
  const [draft, setDraft] = useState<RegionsData | null>(null);
  const [saving, setSaving] = useState(false);
  const { toast } = useToast();
  const data = draft ?? remote;

  const handleRegionChange = (group: string, rule: RegionRule) => {
    if (data == null) return;
    setDraft({ ...data, groups: { ...data.groups, [group]: rule } });
  };

  const handleCountryGroupChange = (updated: Record<string, string>) => {
    if (data == null) return;
    setDraft({ ...data, country_group: updated });
  };

  const handleSave = async () => {
    if (data == null) return;
    setSaving(true);
    try {
      await saveRegions(config, { groups: data.groups, country_group: data.country_group, policy_version: data.policy_version, reconsent_interval: data.reconsent_interval });
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

  const isDirty = draft != null;
  return (
    <>
      <PageHeader
        title="Regions & Rules"
        description="Set the consent model and default category states per visitor region. Changing these re-prompts visitors whose stored consent was issued under a different model."
        actions={<RegionActions isDirty={isDirty} saving={saving} onDiscard={() => setDraft(null)} onSave={handleSave} />}
      />
      <div className="page-body stack stack--lg">
        <RegionRulesCard data={data} onRegionChange={handleRegionChange} />
        <CountryGroupCard countryGroup={data.country_group} onChange={handleCountryGroupChange} />
        <PolicyPanel version={data.policy_version} interval={data.reconsent_interval} onChange={(f, v) => setDraft(data != null ? { ...data, [f]: v } : null)} />
      </div>
    </>
  );
}

function RegionActions({ isDirty, saving, onDiscard, onSave }: { isDirty: boolean; saving: boolean; onDiscard: () => void; onSave: () => void }) {
  return (
    <>
      <button className="btn btn--ghost" type="button" disabled={!isDirty} onClick={onDiscard}>Discard</button>
      <button className="btn btn--primary" type="button" disabled={saving} onClick={onSave}>{saving ? "Saving…" : "Save changes"}</button>
    </>
  );
}

function RegionRulesCard({ data, onRegionChange }: { data: RegionsData; onRegionChange: (group: string, rule: RegionRule) => void }) {
  return (
    <div className="card">
      <div className="card__header">
        <div>
          <div className="card__title">Region consent rules</div>
          <div className="card__subtitle">Default category state is what visitors see BEFORE they make a choice. Opt-in regions default everything off. Opt-out regions default non-necessary categories on.</div>
        </div>
      </div>
      <RegionsTable data={data} onChange={onRegionChange} />
      <div className="card__footer" style={{ justifyContent: "flex-start" }}>
        <span style={{ fontSize: "var(--fs-xs)", color: "hsl(var(--pk-muted))" }}>Necessary is always on and excluded from defaults. Region is resolved server-side from the geo header or the IP lookup provider set in Settings.</span>
      </div>
    </div>
  );
}

function CountryGroupCard({ countryGroup, onChange }: { countryGroup: Record<string, string>; onChange: (updated: Record<string, string>) => void }) {
  return (
    <Panel title="Country → group overrides" description="Route any country or state code to a specific consent group. Useful when your default CDN geo mapping needs correction or when a US state requires CCPA treatment.">
      <CountryGroupEditor countryGroup={countryGroup} onChange={onChange} />
    </Panel>
  );
}

type PolicyPanelProps = { version: string; interval: number; onChange: (field: "policy_version" | "reconsent_interval", val: string | number) => void };

function PolicyPanel({ version, interval, onChange }: PolicyPanelProps) {
  return (
    <Panel title="Policy version & re-consent">
      <div className="grid-2">
        <Field label="Policy version" htmlFor="policy-version" description="Bump this whenever your cookie or privacy policy changes. Any visitor whose stored consent references an older version will be re-prompted on their next visit.">
          <Input id="policy-version" value={version} onChange={(e) => onChange("policy_version", e.target.value)} placeholder="e.g. 2024-03-01" />
        </Field>
        <Field label="Re-consent interval (days)" htmlFor="reconsent-interval" description="How long a stored consent record remains valid before the visitor sees the banner again. Minimum 30 days. GDPR guidance typically suggests 6–12 months.">
          <Input type="number" id="reconsent-interval" value={String(interval)} onChange={(e) => onChange("reconsent_interval", parseInt(e.target.value, 10))} min="30" max="730" />
        </Field>
      </div>
      <Callout variant="warning">
        <strong style={{ color: "hsl(var(--pk-warning))" }}>Bumping policy version re-prompts all visitors.</strong> Do this when you add new cookie categories, change how data is used, or update your privacy policy in a material way — not for minor wording edits.
      </Callout>
    </Panel>
  );
}
