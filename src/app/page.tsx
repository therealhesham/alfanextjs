import React from 'react';
import Link from 'next/link';
import { getQuotesMapped } from '@/lib/repositories/quotes.repository';
import QuotesListInfinite from '@/components/QuotesListInfinite';

// Helper to safely format bigints
const toString = (val: any) => (val != null ? val.toString() : '');

export default async function QuotesPage() {
  // Fetch and map quotes using the repository (simulating mq.php $FIELDS mapping Next.js style)
  const quotesData = await getQuotesMapped();

  return (
    <div className="max-w-full mx-auto p-3 md:p-6 min-h-screen bg-[var(--color-light-gray)]">
      {/* Header */}
      <div className="card mb-6 flex flex-col md:flex-row justify-between items-center gap-4 md:gap-6">
        <h1 className="text-xl md:text-2xl font-bold text-[var(--color-dark-gray)] flex items-center gap-3">
          <i className="fas fa-file-invoice-dollar text-[var(--color-gold)] text-lg md:text-xl"></i>
          إدارة عروض الأسعار
        </h1>
        <div className="flex flex-col md:flex-row gap-3 md:gap-6 items-center w-full md:w-auto">
          <button className="btn-gray w-full md:w-auto">
            <i className="fas fa-eraser"></i>
            مسح الفلاتر
          </button>
          <Link href="/quotes/new" className="btn-gold w-full md:w-auto text-center justify-center">
            <i className="fas fa-plus"></i>
            عرض سعر جديد
          </Link>
        </div>
      </div>

      {/* Table Container */}
      <div className="card !p-0 overflow-hidden">
        <QuotesListInfinite initialQuotes={quotesData} />
      </div>
    </div>
  );
}

