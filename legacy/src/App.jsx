import { useQuery } from "convex/react";
import { api } from "../convex/_generated/api";
import { appStage, branchName, convexHost, stageLabel } from "./env";

export default function App() {
  const greeting = useQuery(api.greetings.hello);

  return (
    <main className="page">
      <div className="card">
        <div className="cardHeader">
          <p className="eyebrow">React + Vercel + Convex</p>
          <span className={`stageBadge stageBadge--${appStage}`}>{stageLabel}</span>
        </div>
        <h1>{greeting?.title ?? "Загружаем приветствие..."}</h1>
        <p className="description">
          {greeting?.description ?? "Подключаемся к Convex..."}
        </p>
        <dl className="meta">
          <div>
            <dt>Backend</dt>
            <dd>{convexHost}</dd>
          </div>
          {branchName ? (
            <div>
              <dt>Branch</dt>
              <dd>{branchName}</dd>
            </div>
          ) : null}
        </dl>
      </div>
    </main>
  );
}
