import { describe, it, expect } from "vitest";
import { buildPageRange } from "./Pager";

describe("buildPageRange", () => {
  it("returns all pages when total <= 7", () => {
    expect(buildPageRange(1, 5)).toEqual([1, 2, 3, 4, 5]);
    expect(buildPageRange(3, 7)).toEqual([1, 2, 3, 4, 5, 6, 7]);
  });

  it("shows ellipsis after page 1 when current > 3", () => {
    const pages = buildPageRange(5, 10);
    expect(pages[0]).toBe(1);
    expect(pages[1]).toBe("ellipsis");
    expect(pages).toContain(5);
  });

  it("shows ellipsis before last page when current < total - 2", () => {
    const pages = buildPageRange(2, 10);
    const lastEllipsis = pages.lastIndexOf("ellipsis");
    expect(lastEllipsis).toBeGreaterThan(-1);
    expect(pages[pages.length - 1]).toBe(10);
  });

  it("shows no trailing ellipsis when near end", () => {
    const pages = buildPageRange(9, 10);
    expect(pages[pages.length - 1]).toBe(10);
    const trailingEllipsis = pages.indexOf("ellipsis", pages.indexOf(9));
    expect(trailingEllipsis).toBe(-1);
  });

  it("always includes first and last page", () => {
    const pages = buildPageRange(5, 20);
    expect(pages[0]).toBe(1);
    expect(pages[pages.length - 1]).toBe(20);
  });
});
