'use client';

import React from 'react';
import QuoteNotesSection from './QuoteNotesSection';

interface QuoteNotesModalProps {
  isOpen: boolean;
  onClose: () => void;
  quoteId: string;
}

export default function QuoteNotesModal({ isOpen, onClose, quoteId }: QuoteNotesModalProps) {
  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
      <div className="bg-white rounded-lg shadow-xl w-full max-w-2xl flex flex-col max-h-[90vh]">
        
        {/* Header */}
        <div className="p-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center rounded-t-lg">
          <div className="text-lg font-bold text-gray-800 flex items-center gap-2">
            <i className="fas fa-comments text-[var(--color-gold)]"></i>
            ملاحظات العرض
          </div>
          <button 
            onClick={onClose}
            className="text-gray-500 hover:bg-gray-200 hover:text-gray-800 rounded-md p-1 transition-colors"
          >
            <i className="fas fa-times text-xl"></i>
          </button>
        </div>

        {/* Content */}
        <div className="p-5 overflow-y-auto flex-1 bg-white">
          <QuoteNotesSection quoteId={quoteId} noCard={true} />
        </div>

        {/* Footer */}
        <div className="p-4 border-t border-gray-200 bg-gray-50 flex gap-3 rounded-b-lg">
          <button 
            onClick={onClose}
            className="w-full bg-gray-500 text-white py-2.5 rounded-md font-bold hover:bg-gray-600 transition-colors flex justify-center items-center gap-2"
          >
            <i className="fas fa-times"></i>
            إغلاق
          </button>
        </div>

      </div>
    </div>
  );
}
