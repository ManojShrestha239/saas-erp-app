import './app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'NexusERP';

createInertiaApp({
  title: (title) => `${title} - ${appName}`,
  resolve: async (name: string) => {
    const pages = import.meta.glob<Record<string, unknown>>(
      './features/**/pages/**/*.tsx'
    );

    const parts = name.split('/');
    const target =
      parts.length === 2
        ? `./features/${parts[0]}/pages/${parts[1]}.tsx`
        : `./features/${parts[0]}/pages/${parts[parts.length - 1]}.tsx`;

    if (pages[target]) {
      const page = await pages[target]!();
      return (page.default || page) as React.ComponentType;
    }

    // Fallback: search by filename
    const filename = parts[parts.length - 1];
    for (const path in pages) {
      if (path.endsWith(`/${filename}.tsx`)) {
        const page = await pages[path]!();
        return (page.default || page) as React.ComponentType;
      }
    }

    throw new Error(`Page ${name} not found in features/**/pages/`);
  },
  setup({ el, App, props }) {
    const root = createRoot(el);
    root.render(<App {...props} />);
  },
});
