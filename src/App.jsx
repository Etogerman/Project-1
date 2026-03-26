import { useQuery } from "convex/react";
import { api } from "../convex/_generated/api";

export default function App() {
  const greeting = useQuery(api.greetings.hello);

  return (
    <main className="page">
      <div className="card">
        <p className="eyebrow">React + Vercel + Convex</p>
        <h1>{greeting?.title ?? "Загружаем приветствие..."}</h1>
        <p className="description">
          {greeting?.description ?? "Подключаемся к Convex..."}
        </p>
      </div>
    </main>
  );
}
