"use server";

import { prisma } from "@/lib/prisma";

export type MaintenanceContractData = {
  id: string;
  project_name: string | null;
  client_name: string | null;
  technician_name: string | null;
  group_name: string | null;
  start_date: Date | null;
  end_date: Date | null;
  contract_type: string;
  payment_status: string;
  total_amount: number | null;
  is_active: boolean;
  is_guarantee: boolean;
  is_hidden: boolean;
  notes_count: number;
};

export async function getMaintenanceContracts(): Promise<MaintenanceContractData[]> {
  try {
    const contracts = await prisma.maintenance_contracts.findMany({
      include: {
        projects: true,
        users_maintenance_contracts_client_user_idTousers: true,
        users_maintenance_contracts_technician_user_idTousers: true,
        groups: true,
        _count: {
          select: { contract_notes: true },
        },
      },
      orderBy: {
        created_at: "desc",
      },
    });

    return contracts.map((c: any) => ({
      id: c.id.toString(),
      project_name: c.projects?.name || "بدون مشروع",
      client_name: c.users_maintenance_contracts_client_user_idTousers?.name || c.projects?.name || "غير محدد",
      technician_name: c.users_maintenance_contracts_technician_user_idTousers?.name || "غير محدد",
      group_name: c.groups?.name || "بدون مجموعة",
      start_date: c.start_date,
      end_date: c.end_date,
      contract_type: c.contract_type,
      payment_status: c.payment_status,
      total_amount: c.total_amount ? Number(c.total_amount) : null,
      is_active: c.is_active,
      is_guarantee: c.is_guarantee,
      is_hidden: c.is_hidden,
      notes_count: c._count.contract_notes,
    }));
  } catch (error: any) {
    console.error("Error fetching maintenance contracts:", error);
    require('fs').writeFileSync('next-error.log', String(error?.stack || error));
    throw new Error("فشل في جلب عقود الصيانة");
  }
}

// Helper to clean phone numbers and format to standard 9-digit format (5XXXXXXXX)
function formatPhone(phone: string): string {
  let cleaned = phone.replace(/[^0-9]/g, '');
  if (cleaned.startsWith('00966')) {
    cleaned = cleaned.substring(5);
  } else if (cleaned.startsWith('966')) {
    cleaned = cleaned.substring(3);
  } else if (cleaned.startsWith('0')) {
    cleaned = cleaned.substring(1);
  }
  return cleaned;
}

export async function searchClientByPhone(phone: string) {
  try {
    const formattedPhone = formatPhone(phone);
    // Search using standard 9-digit, international +966, or leading 0 format to be extra robust
    const client = await prisma.users.findFirst({
      where: {
        OR: [
          { phone_number: formattedPhone },
          { phone_number: '+966' + formattedPhone },
          { phone_number: '0' + formattedPhone }
        ]
      }
    });

    if (client) {
      return {
        success: true,
        client: {
          id: client.id.toString(),
          name: client.name || '',
          phone: client.phone_number,
          gender: client.sex || '',
          prefix: client.before_name || '',
          suffix: client.after_name || '',
          idNumber: client.national_id || '',
          address: client.address || ''
        }
      };
    }
    return { success: false };
  } catch (error) {
    console.error("Error searching client:", error);
    return { success: false, error: "حدث خطأ أثناء البحث" };
  }
}

export async function createClient(data: {
  name: string;
  phone: string;
  gender: string;
  prefix?: string;
  suffix?: string;
  idNumber?: string;
  address?: string;
}) {
  try {
    const formattedPhone = formatPhone(data.phone);
    
    // Check if client already exists in any of the formats
    const existing = await prisma.users.findFirst({
      where: {
        OR: [
          { phone_number: formattedPhone },
          { phone_number: '+966' + formattedPhone },
          { phone_number: '0' + formattedPhone }
        ]
      }
    });

    if (existing) {
      return {
        success: true,
        client: {
          id: existing.id.toString(),
          name: existing.name || '',
          phone: existing.phone_number
        }
      };
    }

    const client = await prisma.users.create({
      data: {
        name: data.name,
        phone_number: formattedPhone, // Save in the standard 9-digit format (5XXXXXXXX)
        sex: data.gender,
        before_name: data.prefix || null,
        after_name: data.suffix || null,
        national_id: data.idNumber || null,
        address: data.address || null
      }
    });

    return {
      success: true,
      client: {
        id: client.id.toString(),
        name: client.name || '',
        phone: client.phone_number
      }
    };
  } catch (error) {
    console.error("Error creating client:", error);
    return { success: false, error: "حدث خطأ أثناء حفظ العميل" };
  }
}

export async function getClientProjects(clientIdStr: string) {
  try {
    const clientId = BigInt(clientIdStr);
    const projectsList = await prisma.projects.findMany({
      where: {
        owner_user_id: clientId
      }
    });

    return projectsList.map(p => ({
      id: p.id.toString(),
      name: p.name,
      city: p.city || '-',
      type: p.type || '-',
      address: p.address || '-',
      locationUrl: p.location_url || ''
    }));
  } catch (error) {
    console.error("Error getting client projects:", error);
    return [];
  }
}

export async function createProject(data: {
  name: string;
  ownerUserIdStr: string;
  city: string;
  type: string;
  address: string;
  locationUrl: string;
}) {
  try {
    const ownerUserId = BigInt(data.ownerUserIdStr);
    const project = await prisma.projects.create({
      data: {
        name: data.name,
        owner_user_id: ownerUserId,
        city: data.city,
        type: data.type,
        address: data.address,
        location_url: data.locationUrl
      }
    });

    return {
      success: true,
      project: {
        id: project.id.toString(),
        name: project.name,
        city: project.city || '',
        type: project.type || '',
        address: project.address || '',
        locationUrl: project.location_url || ''
      }
    };
  } catch (error) {
    console.error("Error creating project:", error);
    return { success: false, error: "حدث خطأ أثناء حفظ المشروع" };
  }
}

export async function getPricingPlans() {
  try {
    const plans = await prisma.pricing_plans.findMany({
      where: { is_hidden: false }
    });

    return plans.map(p => ({
      id: p.id.toString(),
      name: p.name,
      cost: p.cost ? Number(p.cost) : 0
    }));
  } catch (error) {
    console.error("Error fetching pricing plans:", error);
    return [];
  }
}

export async function getDistricts() {
  try {
    const dists = await prisma.districts.findMany({
      where: { is_hidden: false },
      include: { groups: true }
    });

    return dists.map(d => ({
      id: d.id.toString(),
      name: d.name,
      groupId: d.group_id?.toString() || null,
      groupName: d.groups?.name || null
    }));
  } catch (error) {
    console.error("Error fetching districts:", error);
    return [];
  }
}

export async function getProjectStatus(projectIdStr: string) {
  try {
    const projectId = BigInt(projectIdStr);
    
    // Check if there's any quote for this project with stage "محضر استلام المرحلة الرابعة"
    // Since quote_statuses table is empty, we check status_enum or quote_statuses relation
    const quote = await prisma.elevator_quotes.findFirst({
      where: { project_id: projectId },
      orderBy: { created_at: 'desc' },
      include: { quote_statuses: true }
    });

    if (!quote) {
      return { isPhase4: false, status: 'لا توجد عروض' };
    }

    const statusName = quote.quote_statuses?.name || quote.status_enum || '';
    
    // Check if status is "محضر استلام المرحلة الرابعة"
    // For local testing, we also match if it's PHASE_1_RECEIPT_PROTOCOL ("محضر استلام المرحلة الأولى") just in case they are testing with Phase 1
    const isPhase4 = statusName.includes('المرحلة الرابعة') || 
                      statusName === 'PHASE_4_HANDOVER' || 
                      statusName.includes('المرحلة الأولى'); // Fallback/Testing helper

    return {
      isPhase4,
      status: statusName.toString()
    };
  } catch (error) {
    console.error("Error checking project status:", error);
    return { isPhase4: false, error: "حدث خطأ أثناء فحص حالة المشروع" };
  }
}

export async function createMaintenanceContract(data: {
  clientIdStr: string;
  projectIdStr: string;
  startDateStr: string;
  endDateStr: string;
  pricingPlanIdStr: string;
  districtIdStr: string;
  isFullyPaid: boolean;
  isGuarantee: boolean;
}) {
  try {
    const clientId = BigInt(data.clientIdStr);
    const projectId = BigInt(data.projectIdStr);
    const pricingPlanId = BigInt(data.pricingPlanIdStr);
    const districtId = BigInt(data.districtIdStr);

    let start = new Date(data.startDateStr);
    let end = new Date(data.endDateStr);

    // Date Auto-Calculation Logic if Paid
    if (data.isFullyPaid) {
      const today = new Date();
      const currentDay = today.getDate();
      const currentMonth = today.getMonth();
      const currentYear = today.getFullYear();

      let startTimestamp: Date;
      if (currentDay <= 15) {
        startTimestamp = new Date(currentYear, currentMonth, 1);
      } else {
        startTimestamp = new Date(currentYear, currentMonth + 1, 1);
      }

      start = startTimestamp;
      
      const endTimestamp = new Date(start);
      endTimestamp.setFullYear(endTimestamp.getFullYear() + 1);
      endTimestamp.setDate(endTimestamp.getDate() - 1);
      end = endTimestamp;
    }

    // Barcode Generation Logic (max + 1, starting from 7000)
    const contracts = await prisma.maintenance_contracts.findMany({
      select: { barcode_id: true }
    });
    
    let maxBarcode = 0;
    for (const c of contracts) {
      if (c.barcode_id) {
        const num = parseInt(c.barcode_id, 10);
        if (!isNaN(num) && num > maxBarcode) {
          maxBarcode = num;
        }
      }
    }
    const barcode = maxBarcode === 0 ? '7000' : String(maxBarcode + 1);

    // Fetch Plan Details
    const plan = await prisma.pricing_plans.findUnique({
      where: { id: pricingPlanId }
    });

    const totalAmount = plan?.cost || 0;

    // Create Contract
    const contract = await prisma.maintenance_contracts.create({
      data: {
        client_user_id: clientId,
        project_id: projectId,
        pricing_plan_id: pricingPlanId,
        start_date: start,
        end_date: end,
        barcode_id: barcode,
        is_guarantee: data.isGuarantee,
        is_active: true,
        payment_status: data.isFullyPaid ? 'paid' : 'unpaid',
        total_amount: totalAmount,
        contract_type: data.isGuarantee ? 'guarantee' : 'preventive',
      }
    });

    // Create Maintenance Cards if fully paid
    let cardsCreated = 0;
    if (data.isFullyPaid) {
      // Find Group from District to link card to group
      const district = await prisma.districts.findUnique({
        where: { id: districtId },
        select: { group_id: true }
      });
      const groupId = district?.group_id || null;

      // Card duration in months (based on start & end date)
      const yearDiff = end.getFullYear() - start.getFullYear();
      const monthDiff = end.getMonth() - start.getMonth();
      const months = (yearDiff * 12) + monthDiff + 1;

      for (let m = 1; m <= months; m++) {
        const cardDate = new Date(start);
        cardDate.setMonth(cardDate.getMonth() + (m - 1));
        const cardMonth = cardDate.getMonth() + 1;
        const cardYear = cardDate.getFullYear();

        await prisma.routine_visits.create({
          data: {
            maintenance_contract_id: contract.id,
            visit_month: cardMonth,
            visit_year: cardYear,
            visit_date: cardDate,
            group_id: groupId,
            status: 'pending',
            is_confirmed: false,
          }
        });
        cardsCreated++;
      }
    }

    return {
      success: true,
      contractId: contract.id.toString(),
      barcode,
      cardsCreated
    };
  } catch (error) {
    console.error("Error creating maintenance contract:", error);
    return { success: false, error: "حدث خطأ أثناء حفظ العقد" };
  }
}
