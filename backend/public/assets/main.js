import { renderCreate, renderOpen } from './ui/views.js';
const root = document.querySelector('#app');
if (root === null) {
    throw new Error('ECHO root element is missing.');
}
const appRoot = root;
function route() {
    if (window.location.hash.startsWith('#/open/')) {
        renderOpen(appRoot);
    }
    else {
        renderCreate(appRoot);
    }
}
window.addEventListener('hashchange', route);
route();
