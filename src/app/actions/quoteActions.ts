'use server';

import { PrismaClient, quote_status_enum } from '@prisma/client';
import { getQuotesMapped } from '@/lib/repositories/quotes.repository';

const prisma = new PrismaClient();

// Map Arabic string back to enum value
const statusMap: Record<string, quote_status_enum> = {
  "اعتماد العرض من الادارة": quote_status_enum.MANAGEMENT_QUOTE_APPROVAL,
  "اعتماد العرض من العميل": quote_status_enum.CLIENT_QUOTE_APPROVAL,
  "اعتماد العقد من الادارة": quote_status_enum.MANAGEMENT_CONTRACT_APPROVAL,
  "اعتماد العقد من العميل": quote_status_enum.CLIENT_CONTRACT_APPROVAL,
  "سداد الدفعة الاولى": quote_status_enum.FIRST_PAYMENT,
  "طلب مواد المرحلة الاولى": quote_status_enum.PHASE_1_MATERIAL_ORDER,
  "تسليم مواد المرحلة الأولى": quote_status_enum.PHASE_1_MATERIAL_DELIVERY,
  "استلام مواد المرحلة الأولى": quote_status_enum.PHASE_1_MATERIAL_RECEIPT,
  "محضر استلام المرحلة الأولى": quote_status_enum.PHASE_1_RECEIPT_PROTOCOL,
  "ملغي": quote_status_enum.CANCELLED,
};

// Also map Enum back to Arabic string for fetching
const reverseMap: Record<quote_status_enum, string> = {
  [quote_status_enum.MANAGEMENT_QUOTE_APPROVAL]: "اعتماد العرض من الادارة",
  [quote_status_enum.CLIENT_QUOTE_APPROVAL]: "اعتماد العرض من العميل",
  [quote_status_enum.MANAGEMENT_CONTRACT_APPROVAL]: "اعتماد العقد من الادارة",
  [quote_status_enum.CLIENT_CONTRACT_APPROVAL]: "اعتماد العقد من العميل",
  [quote_status_enum.FIRST_PAYMENT]: "سداد الدفعة الاولى",
  [quote_status_enum.PHASE_1_MATERIAL_ORDER]: "طلب مواد المرحلة الاولى",
  [quote_status_enum.PHASE_1_MATERIAL_DELIVERY]: "تسليم مواد المرحلة الأولى",
  [quote_status_enum.PHASE_1_MATERIAL_RECEIPT]: "استلام مواد المرحلة الأولى",
  [quote_status_enum.PHASE_1_RECEIPT_PROTOCOL]: "محضر استلام المرحلة الأولى",
  [quote_status_enum.CANCELLED]: "ملغي",
};

// Safe number parser for Arabic numerals and commas
function safeParseFloat(val: any): number | null {
  if (!val) return null;
  const str = String(val)
    .replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d).toString()) // Arabic to English
    .replace(/,/g, ''); // Remove commas
  const parsed = parseFloat(str);
  return isNaN(parsed) ? null : parsed;
}

export async function getQuoteStatus(quoteIdStr: string) {
  try {
    const quoteId = BigInt(quoteIdStr);
    const quote = await prisma.elevator_quotes.findUnique({
      where: { id: quoteId },
      select: { status_enum: true }
    });

    if (!quote || !quote.status_enum) {
      return null;
    }

    return reverseMap[quote.status_enum];
  } catch (error) {
    console.error('Error fetching quote status:', error);
    return null;
  }
}

export async function updateQuoteStatus(quoteIdStr: string, statusLabel: string) {
  try {
    const enumValue = statusMap[statusLabel];
    if (!enumValue) {
      throw new Error(`Invalid status label: ${statusLabel}`);
    }

    const quoteId = BigInt(quoteIdStr);
    await prisma.elevator_quotes.update({
      where: { id: quoteId },
      data: { status_enum: enumValue }
    });

    return { success: true, status: enumValue };
  } catch (error) {
    console.error('Error updating quote status:', error);
    return { success: false, error: 'Failed to update quote status' };
  }
}

export async function getClientByPhone(phone: string) {
  try {
    const user = await prisma.users.findFirst({
      where: {
        phone_number: phone,
      },
    });

    if (user) {
      return { success: true, user: { id: user.id.toString(), name: user.name, phone: user.phone_number } };
    }
    return { success: false, error: 'Client not found' };
  } catch (error) {
    console.error('Error finding client:', error);
    return { success: false, error: 'Database error' };
  }
}

export async function createQuote(formData: any, clientPhone: string, clientName: string) {
  try {
    // 1. Find or create client
    let clientId = null;
    let client = await prisma.users.findFirst({
      where: { phone_number: clientPhone },
    });

    if (client) {
      clientId = client.id;
    } else {
      // Create new client
      const newClient = await prisma.users.create({
        data: {
          name: clientName || 'عميل جديد',
          phone_number: clientPhone,
        },
      });
      clientId = newClient.id;
    }

    // 2. Map form data
    const quoteData: any = {
      client_user_id: clientId,
      created_by_user_id: 1, // Mocked to admin for now
      number_of_elevators: parseInt(formData.number_of_elevators) || 1,
      machine_type: formData.machine_type || null,
      machine_position: formData.machine_position || null,
      number_of_stops: parseInt(formData.number_of_stops) || null,
      load_kg: safeParseFloat(formData.load_kg),
      number_of_persons: parseInt(formData.number_of_persons) || null,
      number_of_entrances: parseInt(formData.number_of_entrances) || null,
      stop_names: formData.stop_names || null,
      shaft_material: formData.shaft_material || null,
      shaft_internal_size: formData.shaft_internal_size || null,
      car_frame: formData.car_frame || null,
      car_finish: formData.car_finish || null,
      inside_car_dimensions: formData.inside_car_dimensions || null,
      floor: formData.floor || null,
      roof: formData.roof || null,
      door_operation: formData.door_operation || null,
      door_dimensions: formData.door_dimensions || null,
      inner_door: formData.inner_door || null,
      landing_door_main: formData.landing_door_main || null,
      landing_door_other: formData.landing_door_other || null,
      guide_rail: formData.guide_rail || null,
      counterweight_guide_rails: formData.counterweight_guide_rails || null,
      traction_ropes: formData.traction_ropes || null,
      traveling_cable: formData.traveling_cable || null,
      operation_method: formData.operation_method || null,
      electrical_current: formData.electrical_current || null,
      cop: formData.cop || null,
      emergency_light: formData.emergency_light || null,
      total_price: safeParseFloat(formData.total_price),
      discount_amount: safeParseFloat(formData.discount_amount),
      price_details: formData.price_details || null,
      supply_and_install: formData.supply_and_install || null,
      warranty_and_free_maintenance: formData.warranty_and_free_maintenance || null,
      preparatory_works: formData.preparatory_works || null,
      status_enum: quote_status_enum.MANAGEMENT_QUOTE_APPROVAL,
    };

    // 3. Insert Quote
    const newQuote = await prisma.elevator_quotes.create({
      data: quoteData
    });
    
    // 4. Update quote number to ID for now if needed, or leave it
    await prisma.elevator_quotes.update({
      where: { id: newQuote.id },
      data: { quote_number: Number(newQuote.id) + 1000 }
    });

    return { success: true, quoteId: newQuote.id.toString() };

  } catch (error) {
    console.error('Error creating quote:', error);
    return { success: false, error: 'Failed to create quote' };
  }
}

export async function updateQuoteDetails(quoteIdStr: string, formData: any) {
  try {
    const quoteId = BigInt(quoteIdStr);

    const quoteData: any = {
      number_of_elevators: parseInt(formData.number_of_elevators) || 1,
      machine_type: formData.machine_type || null,
      machine_position: formData.machine_position || null,
      number_of_stops: parseInt(formData.number_of_stops) || null,
      load_kg: safeParseFloat(formData.load_kg),
      number_of_persons: parseInt(formData.number_of_persons) || null,
      number_of_entrances: parseInt(formData.number_of_entrances) || null,
      stop_names: formData.stop_names || null,
      shaft_material: formData.shaft_material || null,
      shaft_internal_size: formData.shaft_internal_size || null,
      car_frame: formData.car_frame || null,
      car_finish: formData.car_finish || null,
      inside_car_dimensions: formData.inside_car_dimensions || null,
      floor: formData.floor || null,
      roof: formData.roof || null,
      door_operation: formData.door_operation || null,
      door_dimensions: formData.door_dimensions || null,
      inner_door: formData.inner_door || null,
      landing_door_main: formData.landing_door_main || null,
      landing_door_other: formData.landing_door_other || null,
      guide_rail: formData.guide_rail || null,
      counterweight_guide_rails: formData.counterweight_guide_rails || null,
      traction_ropes: formData.traction_ropes || null,
      traveling_cable: formData.traveling_cable || null,
      operation_method: formData.operation_method || null,
      electrical_current: formData.electrical_current || null,
      cop: formData.cop || null,
      emergency_light: formData.emergency_light || null,
      total_price: safeParseFloat(formData.total_price),
      discount_amount: safeParseFloat(formData.discount_amount),
      price_details: formData.price_details || null,
      supply_and_install: formData.supply_and_install || null,
      warranty_and_free_maintenance: formData.warranty_and_free_maintenance || null,
      preparatory_works: formData.preparatory_works || null,
    };

    await prisma.elevator_quotes.update({
      where: { id: quoteId },
      data: quoteData
    });

    return { success: true };
  } catch (error) {
    console.error('Error updating quote details:', error);
    return { success: false, error: 'Failed to update quote details' };
  }
}

export async function loadMoreQuotes(page: number, limit: number = 20) {
  try {
    const quotes = await getQuotesMapped(page, limit);
    return { success: true, quotes };
  } catch (error) {
    console.error('Error loading more quotes:', error);
    return { success: false, quotes: [] };
  }
}
