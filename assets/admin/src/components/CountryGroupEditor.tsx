import { useState } from "react";
import { Field, Input, Select, Badge } from "@pk/ui";

const GROUPS = ["EU", "UK", "CA", "ROW"] as const;
type Group = (typeof GROUPS)[number];

type Props = {
  countryGroup: Record<string, string>;
  onChange: (updated: Record<string, string>) => void;
};

export function CountryGroupEditor({ countryGroup, onChange }: Props) {
  const handleRemove = (code: string) => {
    const next = { ...countryGroup };
    delete next[code];
    onChange(next);
  };

  const handleGroupChange = (code: string, group: string) => {
    onChange({ ...countryGroup, [code]: group });
  };

  const entries = Object.entries(countryGroup).sort(([a], [b]) => a.localeCompare(b));

  return (
    <div className="stack">
      <table className="data-table">
        <thead>
          <tr>
            <th>Country / state code</th>
            <th>Consent group</th>
            <th className="col-actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          {entries.map(([code, group]) => (
            <CodeRow key={code} code={code} group={group} onGroupChange={handleGroupChange} onRemove={handleRemove} />
          ))}
          {entries.length === 0 && (
            <tr>
              <td colSpan={3} className="muted" style={{ fontSize: "var(--fs-xs)", textAlign: "center", padding: "var(--sp-4)" }}>
                No overrides — all countries fall through to the default group rules.
              </td>
            </tr>
          )}
        </tbody>
      </table>
      <AddRow countryGroup={countryGroup} onChange={onChange} />
      <GroupLegend />
    </div>
  );
}

function CodeRow({ code, group, onGroupChange, onRemove }: { code: string; group: string; onGroupChange: (code: string, group: string) => void; onRemove: (code: string) => void }) {
  return (
    <tr>
      <td><span className="data-table__primary mono">{code}</span></td>
      <td>
        <Select className="select--inline" aria-label={`Group for ${code}`} value={group} onChange={(e) => onGroupChange(code, e.target.value)}>
          {GROUPS.map((g) => <option key={g} value={g}>{g}</option>)}
        </Select>
      </td>
      <td className="col-actions">
        <div className="data-table__actions">
          <button className="icon-btn icon-btn--danger" type="button" aria-label={`Remove ${code}`} onClick={() => onRemove(code)}>
            <TrashIcon />
          </button>
        </div>
      </td>
    </tr>
  );
}

function AddRow({ countryGroup, onChange }: Props) {
  const [newCode, setNewCode] = useState("");
  const [newGroup, setNewGroup] = useState<Group>("ROW");
  const [codeError, setCodeError] = useState<string | null>(null);

  const handleAdd = () => {
    const code = newCode.trim().toUpperCase();
    if (code === "") {
      setCodeError("Code is required.");
      return;
    }
    setCodeError(null);
    onChange({ ...countryGroup, [code]: newGroup });
    setNewCode("");
    setNewGroup("ROW");
  };

  return (
    <div className="row row--gap-2" style={{ alignItems: "flex-end" }}>
      <Field label="Country / state code" htmlFor="cge-new-code" description={codeError ?? "ISO-3166-1 alpha-2 or sub-region code (e.g. US, US-CA, GB)."}>
        <Input id="cge-new-code" value={newCode} onChange={(e) => { setNewCode(e.target.value); setCodeError(null); }} placeholder="e.g. US" style={{ width: "8rem" }} />
      </Field>
      <Field label="Consent group" htmlFor="cge-new-group">
        <Select id="cge-new-group" value={newGroup} onChange={(e) => setNewGroup(e.target.value as Group)} style={{ width: "8rem" }}>
          {GROUPS.map((g) => <option key={g} value={g}>{g}</option>)}
        </Select>
      </Field>
      <div style={{ paddingBottom: codeError != null ? "var(--sp-5)" : "0" }}>
        <button className="btn btn--secondary" type="button" onClick={handleAdd}>
          <PlusIcon /> Add
        </button>
      </div>
    </div>
  );
}

function GroupLegend() {
  return (
    <div className="field__hint" style={{ marginTop: "var(--sp-1)" }}>
      <Badge variant="accent">CA</Badge> = CCPA/CPRA (California opt-out) &nbsp;
      <Badge variant="muted">EU</Badge> = GDPR opt-in &nbsp;
      <Badge variant="muted">UK</Badge> = UK GDPR opt-in &nbsp;
      <Badge variant="muted">ROW</Badge> = Rest of World
    </div>
  );
}

function TrashIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="none" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <polyline points="3 6 5 6 21 6" />
      <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
      <path d="M10 11v6" /><path d="M14 11v6" />
      <path d="M9 6V4h6v2" />
    </svg>
  );
}

function PlusIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="none" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M12 5v14" /><path d="M5 12h14" />
    </svg>
  );
}
