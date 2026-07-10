export function buildPageRange(current: number, total: number): Array<number | "ellipsis"> {
  if (total <= 7) {
    return Array.from({ length: total }, (_, i) => i + 1);
  }
  const pages: Array<number | "ellipsis"> = [1];
  if (current > 3) pages.push("ellipsis");
  const start = Math.max(2, current - 1);
  const end = Math.min(total - 1, current + 1);
  for (let i = start; i <= end; i++) pages.push(i);
  if (current < total - 2) pages.push("ellipsis");
  pages.push(total);
  return pages;
}

type Props = {
  current: number;
  total: number;
  totalRecords: number;
  perPage: number;
  onChange: (page: number) => void;
};

export function Pager({ current, total, totalRecords, perPage, onChange }: Props) {
  const start = (current - 1) * perPage + 1;
  const end = Math.min(current * perPage, totalRecords);
  const pages = buildPageRange(current, total);

  return (
    <div className="pager">
      <span>{`Showing ${start}–${end} of ${totalRecords} records`}</span>
      <div className="pager__pages">
        <button
          className="pager__btn"
          type="button"
          disabled={current === 1}
          aria-label="Previous page"
          onClick={() => onChange(current - 1)}
        >
          <svg viewBox="0 0 24 24" fill="none" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={{ width: 12, height: 12, stroke: "currentColor" }} aria-hidden="true">
            <polyline points="15 18 9 12 15 6" />
          </svg>
        </button>
        {pages.map((p, i) =>
          p === "ellipsis" ? (
            <span key={`e-${i}`} style={{ padding: "0 4px", fontSize: "var(--fs-xs)" }}>…</span>
          ) : (
            <button
              key={p}
              className={`pager__btn${p === current ? " is-active" : ""}`}
              type="button"
              aria-current={p === current ? "page" : undefined}
              onClick={() => onChange(p)}
            >
              {p}
            </button>
          )
        )}
        <button
          className="pager__btn"
          type="button"
          disabled={current === total}
          aria-label="Next page"
          onClick={() => onChange(current + 1)}
        >
          <svg viewBox="0 0 24 24" fill="none" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={{ width: 12, height: 12, stroke: "currentColor" }} aria-hidden="true">
            <polyline points="9 18 15 12 9 6" />
          </svg>
        </button>
      </div>
    </div>
  );
}
