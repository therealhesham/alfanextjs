import { NextRequest, NextResponse } from 'next/server';

export async function POST(request: NextRequest) {
  try {
    const body = await request.json();
    const { action, quoteId } = body;

    // Simulate server processing time (like template_processor.php generating PDF/Word)
    await new Promise((resolve) => setTimeout(resolve, 1500));

    if (!action || !quoteId) {
      return NextResponse.json({ success: false, message: 'Missing action or quoteId' }, { status: 400 });
    }

    let fileUrl = '';
    let message = '';

    // Handle different actions based on old mq.php logic
    switch (action) {
      case 'generate':
      case 'generate_word':
        // Generate main Word file
        fileUrl = `/simulated/docs/word_quote_${quoteId}.docx`;
        message = 'تم إنشاء ملف Word بنجاح';
        break;
      case 'generate_pdf':
        // Generate main PDF
        fileUrl = `/simulated/docs/pdf_quote_${quoteId}.pdf`;
        message = 'تم إنشاء ملف PDF بنجاح';
        break;
      case 'export_contract':
        // Export Contract Word
        fileUrl = `/simulated/docs/contract_${quoteId}.docx`;
        message = 'تم تصدير العقد بنجاح';
        break;
      case 'generate_PDF_Guarantee':
        // Export Guarantee PDF
        fileUrl = `/simulated/docs/guarantee_${quoteId}.pdf`;
        message = 'تم إنشاء ملف ضمان PDF بنجاح';
        break;
      case 'generate_PDF_deliver':
        // Export Delivery PDF
        fileUrl = `/simulated/docs/delivery_${quoteId}.pdf`;
        message = 'تم إنشاء ملف تسليم PDF بنجاح';
        break;
      default:
        return NextResponse.json({ success: false, message: 'Unknown action' }, { status: 400 });
    }

    return NextResponse.json({
      success: true,
      file_url: fileUrl,
      message,
    });
  } catch (error) {
    console.error('Error generating document:', error);
    return NextResponse.json(
      { success: false, message: 'حدث خطأ أثناء معالجة الطلب' },
      { status: 500 }
    );
  }
}
