import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import {
  Accordion,
  AccordionItem,
  AccordionTrigger,
  AccordionContent,
} from '../accordion';

describe('Accordion Component', () => {
  it('renders single-mode accordion and expands/collapses on click', () => {
    render(
      <Accordion type="single" defaultValue="item-1">
        <AccordionItem value="item-1">
          <AccordionTrigger>Question 1</AccordionTrigger>
          <AccordionContent>Answer 1</AccordionContent>
        </AccordionItem>
        <AccordionItem value="item-2">
          <AccordionTrigger>Question 2</AccordionTrigger>
          <AccordionContent>Answer 2</AccordionContent>
        </AccordionItem>
      </Accordion>
    );

    const trigger1 = screen.getByRole('button', { name: /Question 1/i });
    const trigger2 = screen.getByRole('button', { name: /Question 2/i });

    expect(trigger1).toHaveAttribute('aria-expanded', 'true');
    expect(trigger2).toHaveAttribute('aria-expanded', 'false');
    expect(screen.getByText('Answer 1')).toBeInTheDocument();
    expect(screen.queryByText('Answer 2')).not.toBeInTheDocument();

    // Click Item 2 -> Collapses Item 1 and expands Item 2
    fireEvent.click(trigger2);
    expect(trigger1).toHaveAttribute('aria-expanded', 'false');
    expect(trigger2).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByText('Answer 2')).toBeInTheDocument();
    expect(screen.queryByText('Answer 1')).not.toBeInTheDocument();
  });

  it('renders multiple-mode accordion allowing multiple panels open', () => {
    render(
      <Accordion type="multiple" defaultValue={['item-1']}>
        <AccordionItem value="item-1">
          <AccordionTrigger>Section 1</AccordionTrigger>
          <AccordionContent>Content 1</AccordionContent>
        </AccordionItem>
        <AccordionItem value="item-2">
          <AccordionTrigger>Section 2</AccordionTrigger>
          <AccordionContent>Content 2</AccordionContent>
        </AccordionItem>
      </Accordion>
    );

    const trigger2 = screen.getByRole('button', { name: /Section 2/i });
    fireEvent.click(trigger2);

    expect(screen.getByText('Content 1')).toBeInTheDocument();
    expect(screen.getByText('Content 2')).toBeInTheDocument();
  });
});
