// Bundles the app and inlines it into shell.html, giving one self-contained dist/index.html.
import { build } from "esbuild";
import { mkdirSync, readFileSync, writeFileSync } from "node:fs";

const result = await build({
  entryPoints: ["src/main.jsx"],
  bundle: true,
  minify: true,
  format: "iife",
  write: false,
  jsx: "automatic",
  target: "es2020",
  define: {
    "process.env.NODE_ENV": '"production"',
    __BUILD_TIME__: JSON.stringify(new Date().toISOString()),
  },
});

const js = result.outputFiles[0].text.replace(/<\/script/gi, "<\\/script");
const shell = readFileSync("shell.html", "utf8");
if (!shell.includes("<!--BUNDLE-->")) throw new Error("shell.html has no <!--BUNDLE--> marker");

mkdirSync("dist", { recursive: true });
writeFileSync("dist/index.html", shell.replace("<!--BUNDLE-->", () => `<script>${js}</script>`));
console.log(`dist/index.html written (${Math.round((shell.length + js.length) / 1024)} KB)`);
