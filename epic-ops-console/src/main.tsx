import { createRoot } from "react-dom/client";
import "@/styles/globals.css";
import { App } from "./App";

const container = document.getElementById("epic-ops-root");
if (!container) throw new Error("EPIC ops root element is missing");

createRoot(container).render(<App />);
