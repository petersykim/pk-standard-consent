import { Select, Switch } from "@pk/ui";
import type { RegionRule, RegionsData } from "../api/regions";

const REGION_META: Record<string, { label: string; subtitle: string; countries: string }> = {
  EU: {
    label: "European Union",
    subtitle: "GDPR",
    countries: "AT, BE, BG, CY, CZ, DE, DK, EE, ES, FI, FR, GR, HR, HU, IE, IT, LT, LU, LV, MT, NL, PL, PT, RO, SE, SI, SK + EEA",
  },
  UK: {
    label: "United Kingdom",
    subtitle: "UK GDPR",
    countries: "GB (geo-detected via header or IP lookup)",
  },
  CA: {
    label: "California",
    subtitle: "CCPA / CPRA",
    countries: "US-CA (geo-detected via state header or IP lookup)",
  },
  ROW: {
    label: "Rest of world",
    subtitle: "All others — configurable",
    countries: "All countries not matched above. Safe default is opt-in — change only if your legal counsel confirms.",
  },
};

type Props = {
  data: RegionsData;
  onChange: (group: string, rule: RegionRule) => void;
};

export function RegionsTable({ data, onChange }: Props) {
  const displayGroups = Object.keys(data.groups);

  return (
    <div className="regions-table-wrap">
      <table className="data-table regions-table">
        <thead>
          <tr>
            <th>Region</th>
            <th className="region-model-cell">Consent model</th>
            <th className="category-switch-cell">Preferences default</th>
            <th className="category-switch-cell">Analytics default</th>
            <th className="category-switch-cell">Marketing default</th>
            <th>Countries / territories</th>
          </tr>
        </thead>
        <tbody>
          {displayGroups.map((group) => {
            const rule = data.groups[group];
            if (rule == null) return null;
            const meta = REGION_META[group] ?? { label: group, subtitle: "", countries: "" };
            return (
              <RegionRow key={group} group={group} rule={rule} meta={meta} onChange={onChange} />
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

type RowProps = {
  group: string;
  rule: RegionRule;
  meta: { label: string; subtitle: string; countries: string };
  onChange: (group: string, rule: RegionRule) => void;
};

function RegionRow({ group, rule, meta, onChange }: RowProps) {
  const update = (patch: Partial<RegionRule>) => onChange(group, { ...rule, ...patch });
  return (
    <tr>
      <td>
        <div className="data-table__primary">{meta.label}</div>
        <div className="data-table__secondary">{meta.subtitle}</div>
      </td>
      <td className="region-model-cell">
        <Select
          className="select--inline"
          aria-label={`${meta.label} consent model`}
          value={rule.model}
          onChange={(e) => update({ model: e.target.value as "opt-in" | "opt-out" })}
        >
          <option value="opt-in">Opt-in</option>
          <option value="opt-out">Opt-out</option>
        </Select>
      </td>
      <td className="category-switch-cell">
        <Switch aria-label={`${group} preferences default`} checked={rule.preferences} onCheckedChange={(v) => update({ preferences: v })} />
      </td>
      <td className="category-switch-cell">
        <Switch aria-label={`${group} analytics default`} checked={rule.analytics} onCheckedChange={(v) => update({ analytics: v })} />
      </td>
      <td className="category-switch-cell">
        <Switch aria-label={`${group} marketing default`} checked={rule.marketing} onCheckedChange={(v) => update({ marketing: v })} />
      </td>
      <td>
        <div className="countries-list">{meta.countries}</div>
      </td>
    </tr>
  );
}
