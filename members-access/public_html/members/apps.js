/* =====================================================================
   E3 ADVISOR TOOLKIT — edit this list to add, remove or change apps.

   To add a new app: copy one block from { to }, paste it below the
   last one, and change the details. Keep the comma between blocks.

   key   : short name that ties the card to Site Settings > Members & Access
           (and to private/lib/tools.php). A NEW app needs its key added there too.
   name  : what shows on the page
   url   : the link — for tools in the members area, like /members/tool-name/;
           for outside links (e.g. Gemini), the full link starting with https://
   icon  : any one emoji
   about : one short line saying what it does
   group : the heading it sits under (use an existing one, or type a new one)
   note  : optional — a small reminder shown under the app (or delete the line)
   adminOnly : optional — add  adminOnly: true  and the card shows only to the super admin.

   Who sees which card is now decided on the server from the ticks in Members & Access.
   (Hiding a card is only for tidiness: every tool page is also checked on the server.)
   ===================================================================== */
const APPS = [
  {
    key:   "panel-finder",
    name:  "GE Panel Finder",
    url:   "/members/panel-finder/",
    icon:  "🏥",
    about: "Find Great Eastern panel hospitals, specialists and clinics",
    group: "Servicing"
  },
  {
    key:   "great-journey-finder",
    name:  "Great Journey Finder",
    url:   "/members/great-journey-finder/",
    icon:  "💊",
    about: "Find Great Journey hospitals, clinics, pharmacies, physio and vaccination clinics",
    group: "Servicing"
  },
  {
    key:   "protection-needs",
    name:  "Protection Needs Calculator",
    url:   "/members/protection-needs/",
    icon:  "🛡️",
    about: "Work out a family's life cover gap: income, debts, education, minus what's in place",
    group: "Sales",
    note:  "Client-facing. Prints a one-page summary."
  },
  {
    key:   "sum-assured",
    name:  "Sum Assured Calculator",
    url:   "/members/sum-assured/",
    icon:  "🧮",
    about: "Maximum cover by income, non-income groups, keyman and employer-employee",
    group: "Sales",
    note:  "Based on the Field Underwriting Guide V102026. Agents only."
  },
  {
    key:   "legacy-planner",
    name:  "Legacy Planner",
    url:   "/members/legacy-planner/",
    icon:  "📜",
    about: "Walk clients through their legacy planning checklist",
    group: "Sales"
  },
  {
    key:   "ci-poster-generator",
    name:  "CI Poster Generator",
    url:   "/members/ci-poster-generator/",
    icon:  "🖼️",
    about: "Make the yearly critical illness coverage posters for one client or many, from a typed list or an Excel/CSV file",
    group: "Sales",
    note:  "Client data stays in your browser. Nothing is uploaded."
  },
  {
    key:   "policy-summary",
    name:  "Policy Summary",
    url:   "/members/policy-summary/",
    icon:  "📋",
    about: "Turn a client's policies from any insurer into a one-page coverage summary with charts",
    group: "Sales",
    note:  "Client-facing. PDFs are read in your browser; saving client data is optional."
  },
  {
    key:   "career-code",
    name:  "Career Code",
    url:   "/members/career-code/",
    icon:  "🧭",
    about: "Holland career interest test for screening advisor candidates",
    group: "Recruitment",
    note:  "Your own login protects it. Live tests and paper scores are saved to your candidate list."
  },
  {
    key:   "career-code-candidates",
    name:  "Career Code Candidates",
    url:   "/members/career-code/dashboard/",
    icon:  "🗂️",
    about: "Results from the website test and your live sessions: follow up, assign and review",
    group: "Recruitment",
    note:  "You see your own candidates and your team's."
  },
  {
    key:   "event-links",
    name:  "Event Invite Links",
    url:   "/members/event-links/",
    icon:  "🎟️",
    about: "Copy your personal invite link for each upcoming event and share it on WhatsApp",
    group: "Recruitment",
    note:  "Anyone who registers through your link is credited to you."
  },
  {
    key:   "event-invitees",
    name:  "My Event Invitees",
    url:   "/members/event-invitees/",
    icon:  "🙋",
    about: "See who registered for an event through your link, and your team's, and follow up",
    group: "Recruitment",
    note:  "You see your own invitees and your team's."
  },
  {
    key:   "activity",
    name:  "Team Activity",
    url:   "/members/activity/",
    icon:  "📈",
    about: "Who is adding candidates, where everyone stands, and who needs a follow-up",
    group: "Leader",
    note:  "You see yourself and your team. Nothing from other teams."
  },
  {
    key:   "million-income",
    name:  "Million Income Calculator",
    url:   "/members/million-income/",
    icon:  "💰",
    about: "Project career income from Agent to UM to GM, and the Power of One Policy",
    group: "Recruitment"
  },
  {
    key:   "nominee-form",
    name:  "Nominee Form Filler",
    url:   "/members/nominee-form/",
    icon:  "🖋️",
    about: "Key in nominee or trustee details and print the filled form",
    group: "Admin"
  },
  {
    key:   "hnw-coach",
    name:  "HNW Coach",
    url:   "https://gemini.google.com/gem/13q_jW_75ytI5u3adT3YOBt2A39UI3n1T?usp=sharing",
    icon:  "🎯",
    about: "Practise high-net-worth client conversations and objection handling",
    group: "Coaching",
    note:  "Opens in Gemini. Sign in with a Google account the Gem is shared with."
  },
  {
    key:   "action-plan",
    name:  "Action Plan",
    url:   "/members/action-plan/",
    icon:  "🗺️",
    about: "Your first 90 days step by step: what to do each week, your progress, and what your leader has approved",
    group: "Coaching",
    note:  "Your leader assigns it. Suggested weeks are a guide, not a deadline."
  },
  {
    key:   "team",
    name:  "Team",
    url:   "/members/team/",
    icon:  "👥",
    about: "Your team tree and personal Career Code links; admins change ranks, move and remove members",
    group: "Leader",
    note:  "Everyone sees their own team. Changes: super admin and team admins only."
  },
  {
    key:   "agency-report",
    name:  "Agency Report Generator",
    url:   "/members/agency-report/",
    icon:  "📊",
    about: "Build the monthly agency report deck from the admin's Excel and PDF files",
    group: "Leader",
    note:  "For the agency leader and admin."
  },
  {
    key:   "monthly-report",
    name:  "Monthly Report Messages",
    icon:  "📝",
    group: "Leader",
    url:   "/members/monthly-report/",
    about: "Upload the production Excel, get your mentor and team reports as text or a poster."
  },
  {
    key:   "testimonials",
    name:  "Testimonials",
    url:   "/members/testimonials/",
    icon:  "⭐",
    about: "Add and arrange the client testimonials shown on the website",
    group: "Site Admin",
    note:  "Super admin only.",
    adminOnly: true
  },
  {
    key:   "events",
    name:  "Events",
    url:   "/members/events/",
    icon:  "📅",
    about: "Create events, upload posters, announce them on the homepage and see who registered",
    group: "Site Admin",
    note:  "Super admin only.",
    adminOnly: true
  },
  {
    key:   "site-settings",
    name:  "Site Settings",
    url:   "/members/site-settings/",
    icon:  "⚙️",
    about: "The WhatsApp number, email and office address used across the website",
    group: "Site Admin",
    note:  "Super admin only.",
    adminOnly: true
  },
  {
    key:   "access",
    name:  "Members & Access",
    url:   "/members/access/",
    icon:  "🔑",
    about: "Who can log in, what to call them, and which tools each person can use",
    group: "Site Admin",
    note:  "Super admin only. Adds and removes people on Cloudflare too.",
    adminOnly: true
  },
  {
    key:   "client-portal",
    name:  "Client Portal",
    url:   "/client-portal/",
    icon:  "📇",
    about: "Your clients, premiums due, birthdays, follow-ups and policy analysis",
    group: "Servicing",
    note:  "Only you see your clients. Leaders see counts, never names. Your team leader switches it on."
  },
];

const PAGE_TITLE = "E3 Advisor Toolkit";
const PAGE_INTRO = "Every tool the team uses, in one place.";
/* ===================== End of the part you edit ====================== */
