// Which court or office administers the estate (Malaysian non-Muslim estates).
// Source: Small Estates (Distribution) Act 1955, as amended in 2022 (threshold RM5,000,000 from 15 July 2024).

export const SMALL_ESTATE_LIMIT = 5_000_000;
export const SUMMARY_DISTRIBUTION_LIMIT = 600_000;

export function administrationRoute(plan, outcome, willInPlace) {
  if (plan.muslim) return null;
  const hasMinorHeir = outcome.people.some((p) => p.person.minor && p.person.alive && p.total > 0);
  const minorNote = hasMinorHeir ? " A trustee must hold a minor's share until they come of age." : "";

  if (willInPlace) {
    return {
      title: "High Court — Grant of Probate",
      detail: `A valid will means the executor applies to the High Court for a Grant of Probate, whatever the estate is worth. The Small Estate route is for estates with no will, and hiding a will to use it gets the application rejected.${minorNote}`,
    };
  }
  if (!(outcome.estatePool > 0)) return null;

  if (outcome.estatePool > SMALL_ESTATE_LIMIT) {
    return {
      title: "High Court — Letter of Administration",
      detail: `No will and an estate above RM5 million. The court appoints an administrator, who usually needs two sureties. Expect about 9–24 months or more.${minorNote}`,
    };
  }
  if (outcome.estateProperty <= 0 && outcome.estatePool <= SUMMARY_DISTRIBUTION_LIMIT) {
    return {
      title: "Small Estate — Summary Distribution",
      detail: `No will, no land or buildings in the estate and movable assets of RM600,000 or less. Apply at the Land Office for the deceased's last residential district.${minorNote}`,
    };
  }
  return {
    title: "Small Estate — Distribution Order (Land Office / Amanah Raya)",
    detail: `No will and an estate of RM5 million or less. Apply at the Land Office (for land, at the district where the land is). Allow at least 5–6 months.${minorNote}`,
  };
}
