'use client';

import React, { useState } from 'react';
import { useRouter } from 'next/navigation';
import StatusUpdateModal from './StatusUpdateModal';
import QuoteNotesModal from './QuoteNotesModal';

export default function QuoteActions({ quoteId }: { quoteId: string }) {
  const router = useRouter();
  
  const [wordMenuOpen, setWordMenuOpen] = useState(false);
  const [pdfMenuOpen, setPdfMenuOpen] = useState(false);
  const [statusModalOpen, setStatusModalOpen] = useState(false);
  const [notesModalOpen, setNotesModalOpen] = useState(false);
  const [loading, setLoading] = useState(false);

  const handleView = () => {
    router.push(`/quotes/${quoteId}`);
  };

  const handleEdit = () => {
    router.push(`/quotes/${quoteId}/edit`);
  };

  const generateDocument = async (action: string, type: 'word' | 'pdf') => {
    setWordMenuOpen(false);
    setPdfMenuOpen(false);
    setLoading(true);

    try {
      const res = await fetch('/api/documents', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, quoteId }),
      });

      const data = await res.json();

      if (data.success) {
        alert(data.message + '\nرابط الملف (محاكاة): ' + data.file_url);
      } else {
        alert('فشل إنشاء الملف: ' + (data.message || 'خطأ غير معروف'));
      }
    } catch (error) {
      console.error('Error generating document:', error);
      alert('حدث خطأ أثناء الاتصال بالخادم.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <>
      <div className="flex items-center justify-center gap-2">
        <button className="btn-action btn-view" title="عرض التفاصيل" onClick={handleView} disabled={loading}>
          <i className="fas fa-eye"></i>
        </button>
        <button className="btn-action btn-edit" title="تعديل" onClick={handleEdit} disabled={loading}>
          <i className="fas fa-edit"></i>
        </button>

        <button 
          className="btn-action w-9 h-9 flex items-center justify-center rounded-full text-white bg-amber-500 hover:bg-amber-600 transition-colors" 
          title="تحديث الحالة" 
          onClick={() => setStatusModalOpen(true)} 
          disabled={loading}
        >
          <i className="fas fa-sync-alt"></i>
        </button>

        {/* Notes Button */}
        <button 
          className="btn-action w-9 h-9 flex items-center justify-center rounded-full text-white bg-teal-500 hover:bg-teal-600 transition-colors shadow-sm hover:shadow-md" 
          title="الملاحظات" 
          onClick={() => setNotesModalOpen(true)} 
          disabled={loading}
        >
          <i className="fas fa-comments"></i>
        </button>
        
        {/* Word Dropdown */}
        <div className="relative inline-block group">
          <button 
            className="btn-action btn-word" 
            title="Word" 
            onClick={() => { setWordMenuOpen(!wordMenuOpen); setPdfMenuOpen(false); }}
            disabled={loading}
          >
            {loading && wordMenuOpen ? <i className="fas fa-spinner fa-spin"></i> : <i className="fas fa-file-word"></i>}
          </button>
          {wordMenuOpen && !loading && (
            <div className="absolute left-0 mt-1 w-40 bg-white border border-gray-200 rounded-md shadow-lg z-10 py-1">
              <button 
                className="w-full text-right px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                onClick={() => generateDocument('generate', 'word')}
              >
                عرض Word
              </button>
              <button 
                className="w-full text-right px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                onClick={() => generateDocument('export_contract', 'word')}
              >
                عقد Word
              </button>
            </div>
          )}
        </div>

        {/* PDF Dropdown */}
        <div className="relative inline-block group">
          <button 
            className="btn-action btn-pdf" 
            title="PDF"
            onClick={() => { setPdfMenuOpen(!pdfMenuOpen); setWordMenuOpen(false); }}
            disabled={loading}
          >
            {loading && pdfMenuOpen ? <i className="fas fa-spinner fa-spin"></i> : <i className="fas fa-file-pdf"></i>}
          </button>
          {pdfMenuOpen && !loading && (
            <div className="absolute left-0 mt-1 w-40 bg-white border border-gray-200 rounded-md shadow-lg z-10 py-1">
              <button 
                className="w-full text-right px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                onClick={() => generateDocument('generate_pdf', 'pdf')}
              >
                تحميل PDF
              </button>
              <button 
                className="w-full text-right px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                onClick={() => generateDocument('generate_PDF_Guarantee', 'pdf')}
              >
                تصدير ضمان
              </button>
              <button 
                className="w-full text-right px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                onClick={() => generateDocument('generate_PDF_deliver', 'pdf')}
              >
                تصدير تسليم
              </button>
            </div>
          )}
        </div>
      </div>

      <StatusUpdateModal 
        isOpen={statusModalOpen} 
        onClose={() => setStatusModalOpen(false)} 
        quoteId={quoteId} 
        currentStatusLabel="اعتماد العرض من الادارة" 
      />

      <QuoteNotesModal
        isOpen={notesModalOpen}
        onClose={() => setNotesModalOpen(false)}
        quoteId={quoteId}
      />
    </>
  );
}
