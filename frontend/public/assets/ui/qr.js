const ERROR_CORRECTION_M = 0;
const QUIET_ZONE_MODULES = 4;
const TARGET_CSS_PIXELS = 248;
export function renderQr(canvas, value) {
    const QrCode = window.EchoQRCode;
    if (QrCode === undefined) {
        throw new Error('QR encoder is unavailable.');
    }
    const qr = new QrCode(-1, ERROR_CORRECTION_M);
    qr.addData(value);
    qr.make();
    const count = qr.getModuleCount();
    const totalModules = count + QUIET_ZONE_MODULES * 2;
    const scale = Math.max(4, Math.floor(TARGET_CSS_PIXELS / totalModules));
    const pixels = totalModules * scale;
    const context = canvas.getContext('2d');
    if (context === null) {
        throw new Error('Canvas is unavailable.');
    }
    canvas.width = pixels;
    canvas.height = pixels;
    canvas.style.width = `${pixels}px`;
    canvas.style.height = `${pixels}px`;
    context.imageSmoothingEnabled = false;
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, pixels, pixels);
    context.fillStyle = '#000000';
    for (let row = 0; row < count; row += 1) {
        for (let column = 0; column < count; column += 1) {
            if (!qr.isDark(row, column))
                continue;
            context.fillRect((column + QUIET_ZONE_MODULES) * scale, (row + QUIET_ZONE_MODULES) * scale, scale, scale);
        }
    }
}
