import "./admin.css";
import React from "react";
import { createRoot } from "react-dom/client";
import type { AppConfig } from "./config";
import { App } from "./App";

declare const window: Window & {
  PKStandardConsent?: AppConfig;
};

const config = window.PKStandardConsent;
if (config == null) {
  throw new Error("PKStandardConsent global is missing");
}

const root = document.getElementById("pk-sc-admin-root");
if (root == null) {
  throw new Error("Mount point #pk-sc-admin-root not found");
}

createRoot(root).render(
  <React.StrictMode>
    <App config={config} />
  </React.StrictMode>,
);
