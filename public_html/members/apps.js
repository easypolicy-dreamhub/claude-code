/* =====================================================================
   E3 ADVISOR TOOLKIT — edit this list to add, remove or change apps.

   To add a new app: copy one block from { to }, paste it below the
   last one, and change the details. Keep the comma between blocks.

   name  : what shows on the page
   url   : the link — for tools in the members area, like /members/tool-name/;
           for outside links (e.g. Gemini), the full link starting with https://
   icon  : any one emoji
   about : one short line saying what it does
   group : the heading it sits under (use an existing one, or type a new one)
   note  : optional — a small reminder shown under the app (or delete the line)
   ===================================================================== */
const APPS = [
  {
    name:  "GE Panel Finder",
    url:   "/members/panel-finder/",
    icon:  "🏥",
    about: "Find Great Eastern panel hospitals, specialists and clinics",
    group: "Client Tools"
  },
  {
    name:  "Great Journey Finder",
    url:   "/members/great-journey-finder/",
    icon:  "💊",
    about: "Find Great Journey hospitals, clinics, pharmacies, physio and vaccination clinics",
    group: "Client Tools"
  },
  {
    name:  "Protection Needs Calculator",
    url:   "/members/protection-needs/",
    icon:  "🛡️",
    about: "Work out a family's life cover gap: income, debts, education, minus what's in place",
    group: "Agent Tools",
    note:  "Client-facing. Prints a one-page summary."
  },
  {
    name:  "Sum Assured Calculator",
    url:   "/members/sum-assured/",
    icon:  "🧮",
    about: "Maximum cover by income, non-income groups, keyman and employer-employee",
    group: "Agent Tools",
    note:  "Based on the Field Underwriting Guide V102026. Agents only."
  },
  {
    name:  "Legacy Planner",
    url:   "/members/legacy-planner/",
    icon:  "📜",
    about: "Walk clients through their legacy planning checklist",
    group: "Agent Tools"
  },
  {
    name:  "CI Poster Generator",
    url:   "/members/ci-poster-generator/",
    icon:  "🖼️",
    about: "Make the yearly critical illness coverage posters for one client or many, from a typed list or an Excel/CSV file",
    group: "Agent Tools",
    note:  "Client data stays in your browser. Nothing is uploaded."
  },
  {
    name:  "Career Code",
    url:   "/members/career-code/",
    icon:  "🧭",
    about: "Holland career interest test for screening advisor candidates",
    group: "Recruitment",
    note:  "Advisor notes are PIN-protected. Ask your leader for the PIN."
  },
  {
    name:  "Career Code Candidates",
    url:   "/members/career-code/dashboard/",
    icon:  "🗂️",
    about: "Results from the website test and your live sessions: follow up, assign and review",
    group: "Recruitment",
    note:  "You see your own candidates and your team's."
  },
  {
    name:  "Million Income Calculator",
    url:   "/members/million-income/",
    icon:  "💰",
    about: "Project career income from Agent to UM to GM, and the Power of One Policy",
    group: "Recruitment"
  },
  {
    name:  "Nominee Form Filler",
    url:   "/members/nominee-form/",
    icon:  "🖋️",
    about: "Key in nominee or trustee details and print the filled form",
    group: "Admin"
  },
  {
    name:  "HNW Coach",
    url:   "https://gemini.google.com/gem/13q_jW_75ytI5u3adT3YOBt2A39UI3n1T?usp=sharing",
    icon:  "🎯",
    about: "Practise high-net-worth client conversations and objection handling",
    group: "Coaching",
    note:  "Opens in Gemini. Sign in with a Google account the Gem is shared with."
  },
  {
    name:  "Agency Report Generator",
    url:   "/members/agency-report/",
    icon:  "📊",
    about: "Build the monthly agency report deck from the admin's Excel and PDF files",
    group: "Leader Tools",
    note:  "For the agency leader and admin."
  }
];

const PAGE_TITLE = "E3 Advisor Toolkit";
const PAGE_INTRO = "Every tool the team uses, in one place.";
/* ===================== End of the part you edit ====================== */
