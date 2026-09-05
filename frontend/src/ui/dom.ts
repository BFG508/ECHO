export function clear(node: Element): void {
  node.replaceChildren();
}

export function element<K extends keyof HTMLElementTagNameMap>(
  tag: K,
  className?: string,
  text?: string,
): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag);
  if (className !== undefined) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

export function setBusy(button: HTMLButtonElement, busy: boolean, busyText: string): void {
  if (busy) {
    button.dataset.originalText = button.textContent ?? '';
    button.textContent = busyText;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
  } else {
    button.textContent = button.dataset.originalText ?? button.textContent;
    button.disabled = false;
    button.removeAttribute('aria-busy');
  }
}

export async function copyText(value: string): Promise<void> {
  if (navigator.clipboard?.writeText !== undefined && window.isSecureContext) {
    await navigator.clipboard.writeText(value);
    return;
  }

  const input = document.createElement('textarea');
  input.value = value;
  input.setAttribute('readonly', '');
  input.className = 'clipboard-helper';
  document.body.append(input);
  input.select();
  const copied = document.execCommand('copy');
  input.remove();
  if (!copied) {
    throw new Error('Copy failed.');
  }
}
