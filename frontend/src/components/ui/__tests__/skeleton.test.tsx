import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Skeleton, TableSkeleton, CardSkeleton } from '../skeleton';

describe('Skeleton Components', () => {
  it('renders default Skeleton with role="status" and shimmer class', () => {
    render(<Skeleton data-testid="test-skeleton" className="h-6 w-24" />);
    const skeleton = screen.getByTestId('test-skeleton');
    expect(skeleton).toBeInTheDocument();
    expect(skeleton).toHaveAttribute('role', 'status');
    expect(skeleton).toHaveAttribute('aria-busy', 'true');
    expect(skeleton).toHaveClass('animate-shimmer');
  });

  it('renders TableSkeleton with configurable rows and columns', () => {
    const { container } = render(<TableSkeleton rows={4} columns={3} />);
    expect(screen.getByLabelText(/Loading table data/i)).toBeInTheDocument();
    const headerSkeletons = container.querySelectorAll('.border-b .rounded-md');
    expect(headerSkeletons.length).toBe(3);
    const rowSkeletons = container.querySelectorAll('.divide-y .rounded-md');
    expect(rowSkeletons.length).toBe(12);
  });

  it('renders CardSkeleton with accessible label', () => {
    render(<CardSkeleton />);
    expect(screen.getByLabelText(/Loading card content/i)).toBeInTheDocument();
  });
});
