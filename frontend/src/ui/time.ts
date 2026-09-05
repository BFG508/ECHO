function formatPart(value: number, unit: string): string {
  return `${value} ${unit}${value === 1 ? '' : 's'}`;
}

export function formatRemaining(seconds: number): string {
  const safe = Math.max(0, Math.floor(seconds));
  if (safe < 60) return formatPart(safe, 'second');
  const minutes = Math.floor(safe / 60);
  if (minutes < 60) return `${formatPart(minutes, 'minute')} ${formatPart(safe % 60, 'second')}`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${formatPart(hours, 'hour')} ${formatPart(minutes % 60, 'minute')}`;
  const days = Math.floor(hours / 24);
  return `${formatPart(days, 'day')} ${formatPart(hours % 24, 'hour')}`;
}

export function startCountdown(element: HTMLElement, expiresAtSeconds: number): void {
  const update = (): void => {
    if (!document.body.contains(element)) {
      window.clearInterval(timer);
      return;
    }
    const remaining = Math.max(0, expiresAtSeconds - Math.floor(Date.now() / 1000));
    element.textContent = remaining === 0 ? 'Expired' : formatRemaining(remaining);
    if (remaining === 0) window.clearInterval(timer);
  };

  const timer = window.setInterval(update, 1000);
  update();
}
