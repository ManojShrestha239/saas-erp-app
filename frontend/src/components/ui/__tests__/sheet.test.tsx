import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import {
  Sheet,
  SheetHeader,
  SheetTitle,
  SheetDescription,
  SheetBody,
  SheetFooter,
} from '../sheet';

describe('Sheet Component (Slide-Over Drawer)', () => {
  it('does not render when open is false', () => {
    render(
      <Sheet open={false} onOpenChange={vi.fn()}>
        <div>Hidden Drawer Content</div>
      </Sheet>
    );
    expect(screen.queryByText('Hidden Drawer Content')).not.toBeInTheDocument();
  });

  it('renders drawer dialog when open is true', () => {
    const handleOpenChange = vi.fn();
    render(
      <Sheet open={true} onOpenChange={handleOpenChange}>
        <SheetHeader>
          <SheetTitle>Drawer Record Title</SheetTitle>
          <SheetDescription>Drawer Subtitle Description</SheetDescription>
        </SheetHeader>
        <SheetBody>
          <p>Visible Drawer Content</p>
        </SheetBody>
        <SheetFooter>
          <button type="button">Action</button>
        </SheetFooter>
      </Sheet>
    );

    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(screen.getByText('Drawer Record Title')).toBeInTheDocument();
    expect(screen.getByText('Visible Drawer Content')).toBeInTheDocument();

    // Clicking close button triggers onOpenChange(false)
    const closeBtn = screen.getByLabelText(/Close drawer/i);
    fireEvent.click(closeBtn);
    expect(handleOpenChange).toHaveBeenCalledWith(false);
  });

  it('handles Escape key to close drawer', () => {
    const handleOpenChange = vi.fn();
    render(
      <Sheet open={true} onOpenChange={handleOpenChange}>
        <SheetBody>Content</SheetBody>
      </Sheet>
    );

    fireEvent.keyDown(window, { key: 'Escape' });
    expect(handleOpenChange).toHaveBeenCalledWith(false);
  });
});
