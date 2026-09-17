import { useState } from 'react';
import { AddLinkInputSchema } from '../contracts/input';

interface Props {
  t: (key: string) => string;
  busy: boolean;
  onSubmit: (url: string, description: string) => Promise<boolean>;
  onCancel: () => void;
}

/**
 * The generic "add link" form (Phase 2): a URL + optional description. Validates
 * the URL client-side before hitting the server; on success it clears and closes
 * via the parent's onSubmit result.
 */
export function AddLinkDialog({ t, busy, onSubmit, onCancel }: Props) {
  const [url, setUrl] = useState('');
  const [description, setDescription] = useState('');
  const [localError, setLocalError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();

    // Zod validates + normalises (trim, length caps, http(s) only) in one place.
    const parsed = AddLinkInputSchema.safeParse({ url, description });
    if (!parsed.success) {
      const code = parsed.error.issues[0]?.message ?? 'invalid_url';
      setLocalError(t(code === 'invalid_url' ? 'imatic_el_error_invalid_url' : 'imatic_el_error_generic'));
      return;
    }

    setLocalError(null);
    const ok = await onSubmit(parsed.data.url, parsed.data.description);
    if (ok) {
      setUrl('');
      setDescription('');
    }
  }

  return (
    <form className="imatic-el-add-form" onSubmit={handleSubmit}>
      <div className="imatic-el-field">
        <label>{t('imatic_el_url_label')}</label>
        <input
          type="url"
          className="input-sm"
          value={url}
          placeholder={t('imatic_el_url_placeholder')}
          autoFocus
          onChange={(e) => setUrl(e.target.value)}
        />
      </div>
      <div className="imatic-el-field">
        <label>{t('imatic_el_description_label')}</label>
        <input
          type="text"
          className="input-sm"
          value={description}
          maxLength={2000}
          onChange={(e) => setDescription(e.target.value)}
        />
      </div>

      {localError && <div className="imatic-el-inline-error">{localError}</div>}

      <div className="imatic-el-form-actions">
        <button type="submit" className="btn btn-xs btn-primary btn-round" disabled={busy}>
          {t('imatic_el_save')}
        </button>
        <button
          type="button"
          className="btn btn-xs btn-white btn-round"
          disabled={busy}
          onClick={onCancel}
        >
          {t('imatic_el_cancel')}
        </button>
      </div>
    </form>
  );
}
