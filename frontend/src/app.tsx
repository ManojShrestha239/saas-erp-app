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

    // Resolve matching page component
    for (const path in pages) {
      if (path.endsWith(`/${name}.tsx`)) {
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
