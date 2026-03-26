const stageByVercelEnv = {
  development: "development",
  preview: "preview",
  production: "production",
};

const vercelEnv =
  typeof __VERCEL_ENV__ === "string" ? __VERCEL_ENV__ : undefined;
const gitBranch =
  typeof __VERCEL_GIT_COMMIT_REF__ === "string"
    ? __VERCEL_GIT_COMMIT_REF__
    : undefined;
const explicitStage = import.meta.env.VITE_APP_STAGE;

export const convexUrl = import.meta.env.VITE_CONVEX_URL;

if (!convexUrl) {
  throw new Error("VITE_CONVEX_URL is not configured");
}

export const appStage =
  explicitStage ?? stageByVercelEnv[vercelEnv] ?? import.meta.env.MODE;
export const branchName = gitBranch;
export const convexHost = new URL(convexUrl).host;

export const stageLabelByName = {
  development: "Local development",
  preview: "Staging preview",
  production: "Production",
};

export const stageLabel = stageLabelByName[appStage] ?? appStage;
