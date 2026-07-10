import { Badge, Button, Callout, Input } from "@pk/ui";
import type { Category, Script } from "../api/categories";

const BORDER_CLASS: Record<string, string> = {
  necessary: "category-card--necessary",
  preferences: "category-card--preferences",
  analytics: "category-card--analytics",
  marketing: "category-card--marketing",
};

const BADGE_VARIANT: Record<string, "success" | "accent" | "warning" | "danger" | "muted"> = {
  necessary: "success",
  preferences: "accent",
  analytics: "warning",
  marketing: "danger",
};

type Props = {
  category: Category;
  onDescriptionChange: (slug: string, value: string) => void;
  onRemoveScript: (slug: string, handle: string) => void;
  onAddScript: (slug: string) => void;
};

export function CategoryCard({ category, onDescriptionChange, onRemoveScript, onAddScript }: Props) {
  const { slug, description, scripts } = category;
  const isNecessary = slug === "necessary";
  const borderClass = BORDER_CLASS[slug] ?? "";
  const badgeVariant = BADGE_VARIANT[slug] ?? "muted";
  const scriptLabel = scripts.length === 1 ? "1 script" : `${scripts.length} scripts`;

  return (
    <div className={`card category-card ${borderClass}`}>
      <div className="card__header">
        <div>
          <div className="card__title">{slug.charAt(0).toUpperCase() + slug.slice(1)}</div>
        </div>
        <Badge variant={isNecessary ? "success" : badgeVariant}>
          {isNecessary ? "Always on" : scriptLabel}
        </Badge>
      </div>
      <div className="card__body stack">
        <div className="field">
          <label className="field__label" htmlFor={`desc-${slug}`}>Description shown in preference center</label>
          <Input
            type="text"
            id={`desc-${slug}`}
            value={description}
            onChange={(e) => onDescriptionChange(slug, e.target.value)}
          />
        </div>
        <ScriptTable slug={slug} scripts={scripts} isNecessary={isNecessary} onRemove={onRemoveScript} />
        {isNecessary ? (
          <Callout variant="info">
            Scripts cannot be added to Necessary. If a script is truly necessary, it should load unconditionally through your theme or plugin.
          </Callout>
        ) : (
          <div>
            <Button variant="secondary" size="sm" onClick={() => onAddScript(slug)}>
              <PlusIcon /> Add script to {slug.charAt(0).toUpperCase() + slug.slice(1)}
            </Button>
          </div>
        )}
      </div>
    </div>
  );
}

function ScriptTable({ slug, scripts, isNecessary, onRemove }: { slug: string; scripts: Script[]; isNecessary: boolean; onRemove: (slug: string, handle: string) => void }) {
  return (
    <div>
      <div className="section-heading">Registered scripts</div>
      <table className="data-table">
        <thead>
          <tr>
            <th>Handle / source</th>
            <th>Type</th>
            <th className="col-actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          {scripts.map((s) => (
            <tr key={s.handle}>
              <td>
                <span className="data-table__primary script-handle">{s.handle}</span>
                {s.src !== "" && <div className="data-table__secondary">{s.src}</div>}
              </td>
              <td><Badge variant="muted">{s.type}</Badge></td>
              <td className="col-actions">
                {isNecessary ? (
                  <span className="muted" style={{ fontSize: "var(--fs-xs)" }}>Protected</span>
                ) : (
                  <div className="data-table__actions">
                    <button className="icon-btn icon-btn--danger" type="button" aria-label="Remove script" onClick={() => onRemove(slug, s.handle)}>
                      <TrashIcon />
                    </button>
                  </div>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
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
