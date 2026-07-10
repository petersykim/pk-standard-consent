import { useState, useContext, useCallback } from "react";
import { PageHeader, Panel, Field, Textarea, Input, Spinner, Dialog, useToast } from "@pk/ui";
import { useCategoriesData, saveCategories } from "../api/categories";
import type { CategoriesData } from "../api/categories";
import { CategoryCard } from "../components/CategoryCard";
import { ConfigContext } from "../ConfigContext";

type DialogState = { open: false } | { open: true; slug: string };

function useAddScriptDialog(
  data: CategoriesData | null,
  setDraft: (d: CategoriesData | null) => void,
) {
  const [dialog, setDialog] = useState<DialogState>({ open: false });
  const [handle, setHandle] = useState("");
  const [src, setSrc] = useState("");
  const [provider, setProvider] = useState("");
  const [handleError, setHandleError] = useState<string | null>(null);

  const openFor = (slug: string) => {
    setHandle("");
    setSrc("");
    setProvider("");
    setHandleError(null);
    setDialog({ open: true, slug });
  };

  const close = () => setDialog({ open: false });

  const confirm = () => {
    if (handle.trim() === "") {
      setHandleError("Handle is required.");
      return;
    }
    if (!dialog.open || data == null) return;
    const { slug } = dialog;
    const script = { handle: handle.trim(), src: src.trim(), type: "enqueued" };
    setDraft({
      ...data,
      categories: data.categories.map((c) =>
        c.slug === slug ? { ...c, scripts: [...c.scripts, script] } : c
      ),
    });
    close();
  };

  return { dialog, handle, src, provider, handleError, setHandle, setSrc, setProvider, openFor, close, confirm };
}

function useCategoryDraft(remote: CategoriesData | null) {
  const [draft, setDraft] = useState<CategoriesData | null>(null);
  const data = draft ?? remote;

  const patchDesc = useCallback((slug: string, value: string) => {
    if (data == null) return;
    setDraft({ ...data, categories: data.categories.map((c) => c.slug === slug ? { ...c, description: value } : c) });
  }, [data]);

  const removeScript = useCallback((slug: string, handle: string) => {
    if (data == null) return;
    setDraft({ ...data, categories: data.categories.map((c) => c.slug === slug ? { ...c, scripts: c.scripts.filter((s) => s.handle !== handle) } : c) });
  }, [data]);

  return { data, draft, setDraft, patchDesc, removeScript };
}

export function CategoriesScripts() {
  const config = useContext(ConfigContext);
  const { data: remote, loading, error } = useCategoriesData(config);
  const { data, draft, setDraft, patchDesc, removeScript } = useCategoryDraft(remote);
  const [saving, setSaving] = useState(false);
  const { toast } = useToast();

  const addScriptDialog = useAddScriptDialog(data, setDraft);

  const handleSave = async () => {
    if (data == null) return;
    setSaving(true);
    try {
      await saveCategories(config, data);
      setDraft(null);
      toast({ title: "Saved", variant: "success" });
    } catch {
      toast({ title: "Save failed", variant: "danger" });
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <div className="page-body"><Spinner /></div>;
  if (error != null || data == null) return <div className="page-body"><p className="muted">{error ?? "Failed to load"}</p></div>;

  const isDirty = draft != null;
  return (
    <>
      <PageHeader
        title="Categories & Scripts"
        description="Register scripts to consent categories and configure the banner message shown to visitors."
        actions={<HeaderActions isDirty={isDirty} saving={saving} onDiscard={() => setDraft(null)} onSave={handleSave} />}
      />
      <div className="page-body stack stack--lg">
        <BannerPanel message={data.bannerMessage} policyLink={data.policyLink} onChange={(f, v) => setDraft({ ...data, [f]: v })} />
        {data.categories.map((cat) => (
          <CategoryCard key={cat.slug} category={cat} onDescriptionChange={patchDesc} onRemoveScript={removeScript} onAddScript={addScriptDialog.openFor} />
        ))}
      </div>
      <AddScriptDialog state={addScriptDialog} />
    </>
  );
}

type AddScriptDialogState = ReturnType<typeof useAddScriptDialog>;

function AddScriptDialog({ state }: { state: AddScriptDialogState }) {
  const { dialog, handle, src, provider, handleError, setHandle, setSrc, setProvider, close, confirm } = state;
  const slug = dialog.open ? dialog.slug : "";

  return (
    <Dialog
      open={dialog.open}
      onOpenChange={(open) => { if (!open) close(); }}
      title={`Add script to ${slug.charAt(0).toUpperCase() + slug.slice(1)}`}
      description="The handle must match the WordPress script handle used in wp_enqueue_script."
      footer={
        <>
          <button className="btn btn--ghost" type="button" onClick={close}>Cancel</button>
          <button className="btn btn--primary" type="button" onClick={confirm}>Add script</button>
        </>
      }
    >
      <div className="stack">
        <Field label="Handle" htmlFor="add-script-handle" description={handleError ?? "The wp_enqueue_script handle (required)."}>
          <Input
            id="add-script-handle"
            value={handle}
            onChange={(e) => { setHandle(e.target.value); }}
            placeholder="e.g. google-analytics"
            autoFocus
          />
        </Field>
        <Field label="Source URL" htmlFor="add-script-src" description="Optional. The script's src URL, for reference only.">
          <Input
            id="add-script-src"
            value={src}
            onChange={(e) => setSrc(e.target.value)}
            placeholder="https://www.googletagmanager.com/gtag/js"
          />
        </Field>
        <Field label="Provider" htmlFor="add-script-provider" description="Optional. Free-text provider name shown in the cookie table.">
          <Input
            id="add-script-provider"
            value={provider}
            onChange={(e) => setProvider(e.target.value)}
            placeholder="e.g. Google Analytics"
          />
        </Field>
      </div>
    </Dialog>
  );
}

function HeaderActions({ isDirty, saving, onDiscard, onSave }: { isDirty: boolean; saving: boolean; onDiscard: () => void; onSave: () => void }) {
  return (
    <>
      <button className="btn btn--ghost" type="button" disabled={!isDirty} onClick={onDiscard}>Discard</button>
      <button className="btn btn--primary" type="button" disabled={saving} onClick={onSave}>{saving ? "Saving…" : "Save changes"}</button>
    </>
  );
}

type BannerPanelProps = { message: string; policyLink: string; onChange: (field: "bannerMessage" | "policyLink", val: string) => void };

function BannerPanel({ message, policyLink, onChange }: BannerPanelProps) {
  return (
    <Panel title="Banner message" description="The copy and policy link shown to visitors in the consent banner.">
      <Field label="Consent message" htmlFor="banner-message" description="Shown in the banner and at the top of the preference center. Keep it plain and honest.">
        <Textarea id="banner-message" rows={3} value={message} onChange={(e) => onChange("bannerMessage", e.target.value)} />
      </Field>
      <Field label="Cookie & privacy policy URL" htmlFor="policy-link" description='Linked from the banner as "Cookie Policy" and from the preference center footer.' className="field--narrow">
        <Input type="url" id="policy-link" value={policyLink} onChange={(e) => onChange("policyLink", e.target.value)} />
      </Field>
    </Panel>
  );
}
