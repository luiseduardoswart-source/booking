// Text to the clipboard. The browser only offers navigator.clipboard on https;
// on a local http site this falls back to the older method.
window.copyText = async (text) => {
    try {
        await window.navigator.clipboard.writeText(text);

        return true;
    } catch {
        const field = document.createElement('textarea');
        field.value = text;
        field.style.position = 'fixed';
        field.style.opacity = '0';
        document.body.append(field);
        field.select();

        const succeeded = document.execCommand('copy');
        field.remove();

        return succeeded;
    }
};
