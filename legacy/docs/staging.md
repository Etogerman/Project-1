# Staging: Vercel Preview + Convex non-prod

This project is set up for a simple staging model:

- `Vercel Production` talks to `Convex production`
- `Vercel Preview` talks to one shared `Convex non-prod`
- local development keeps using each developer's normal Convex dev deployment

## Static staging URL

If you want one stable staging address, use a dedicated Git branch called `staging`.

- keep `main` as Production
- keep `staging` as your long-lived staging branch
- point Vercel Preview for `staging` to the shared Convex non-prod backend
- optionally assign a custom domain like `staging.example.com` to the `staging` branch

In Vercel you then get two useful staging URLs:

- branch URL: a stable Vercel-generated URL for the `staging` branch
- custom domain: your own stable domain, if you attach one

Recommended flow:

1. Merge feature branches into `staging`.
2. Vercel rebuilds the `staging` branch.
3. Open the `staging` branch URL or its custom domain.
4. When approved, merge `staging` into `main`.

## Why this setup

This keeps preview deploys cheap and easy to reason about:

- every PR gets a real Vercel Preview URL
- preview data stays isolated from production
- you avoid Convex preview-deployment churn for a small project
- staging stays stable because it is one persistent non-prod backend

## Required Vercel env vars

Set these variables per environment in Vercel Project Settings:

- `Production`: `VITE_CONVEX_URL` = URL of the production Convex deployment
- `Preview`: `VITE_CONVEX_URL` = URL of the shared non-prod Convex deployment
- optional but recommended: `VITE_APP_STAGE=production` for Production and `VITE_APP_STAGE=preview` for Preview

If you use a dedicated `staging` branch, these `Preview` variables will automatically apply to that branch unless you override them branch-specifically in Vercel.

Example:

```txt
Production -> VITE_CONVEX_URL=https://your-prod.convex.cloud
Production -> VITE_APP_STAGE=production
Preview    -> VITE_CONVEX_URL=https://your-staging.convex.cloud
Preview    -> VITE_APP_STAGE=preview
```

Do not commit these values to git. Vercel injects them at build time.

If you want the UI to infer branch and environment automatically from Vercel, enable "Automatically expose System Environment Variables" in the Vercel project settings.

## Local development

Local dev should continue using `.env.local`, normally maintained by `npx convex dev`.
Use `.env.example` only as a template.

## Recommended deployment flow

1. Develop locally against your normal Convex dev deployment.
2. Merge ready changes into `staging`.
3. Deploy backend changes to the shared Convex non-prod deployment.
4. Open the `staging` deployment in Vercel. It will already point at the shared non-prod Convex URL.
5. After staging is validated, merge `staging` into `main`, deploy Convex production, and promote the frontend normally.

## Important note about deploy keys

For this simple staging model, do not attach a shared non-prod `CONVEX_DEPLOY_KEY` to every Vercel Preview build unless you intentionally want every preview deployment to overwrite the same staging backend.

If you later want CI-driven backend deploys, use a dedicated staging branch or a separate workflow, not every preview build.
