import React from "react";
import { PKRoot, ToastProvider } from "@pk/ui";
import type { AppConfig } from "./config";
import { ConfigContext } from "./ConfigContext";
import { CategoriesScripts } from "./screens/CategoriesScripts";
import { CookieScan } from "./screens/CookieScan";
import { RegionsRules } from "./screens/RegionsRules";
import { ConsentLog } from "./screens/ConsentLog";
import { Settings } from "./screens/Settings";

type Props = { config: AppConfig };

function resolveScreen(page: string): React.ReactNode {
  if (page === "pk-standard-consent-scan") return <CookieScan />;
  if (page === "pk-standard-consent-regions") return <RegionsRules />;
  if (page === "pk-standard-consent-log") return <ConsentLog />;
  if (page === "pk-standard-consent-settings") return <Settings />;
  return <CategoriesScripts />;
}

export function App({ config }: Props) {
  return (
    <ConfigContext.Provider value={config}>
      <PKRoot>
        <ToastProvider>
          {resolveScreen(config.page)}
        </ToastProvider>
      </PKRoot>
    </ConfigContext.Provider>
  );
}
