const fs = require('fs');
const csv = require('csv-parser');
const { PrismaClient } = require('@prisma/client');
const prisma = new PrismaClient();

// Helper to safely parse strings with Arabic numerals and text like "630KG"
function safeParseFloat(val) {
  if (!val) return null;
  const str = String(val)
    .replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d).toString()) // Arabic to English
    .replace(/,/g, '') // Remove commas
    .replace(/[^\d.-]/g, ''); // Remove non-numeric characters (like "KG" or spaces)
  const parsed = parseFloat(str);
  return isNaN(parsed) ? null : parsed;
}

// Truncate strings to prevent DB errors on VarChar fields
function trunc(val, maxLen) {
  if (!val) return null;
  const str = String(val);
  return str.length > maxLen ? str.substring(0, maxLen - 3) + '...' : str;
}

// Map Arabic statuses from CSV to our quote_status_enum
function mapStatus(statusText) {
  if (!statusText) return 'MANAGEMENT_QUOTE_APPROVAL';
  const text = String(statusText).trim();
  if (text.includes('ملغي')) return 'CANCELLED';
  if (text.includes('موافقة')) return 'CLIENT_QUOTE_APPROVAL';
  return 'MANAGEMENT_QUOTE_APPROVAL'; // default fallback
}

async function main() {
  const results = [];
  console.log('Reading CSV file...');

  // Start reading the CSV stream
  fs.createReadStream('export - عروض الأسعار - Grid.csv')
    .pipe(csv())
    .on('data', (data) => results.push(data))
    .on('end', async () => {
      console.log(`Parsed ${results.length} rows. Starting import into MySQL...`);
      
      let insertedCount = 0;
      
      for (const row of results) {
        try {
          // 1. Ensure Client User Exists
          let phone = row['رقم الجوال'] ? String(row['رقم الجوال']).trim() : null;
          let clientId = null;
          
          if (phone) {
            // Clean phone (remove spaces, leading zeros after +966)
            phone = phone.replace(/\s+/g, '');
            if (!phone.startsWith('5') && !phone.startsWith('+966')) {
              phone = '5' + phone.replace(/^0+/, ''); 
            }
            if (phone.length > 9 && !phone.startsWith('+')) {
              phone = phone.slice(-9); // keep last 9 digits assuming Saudi
            }
            
            // Check if user exists
            let user = await prisma.users.findFirst({
              where: { phone_number: phone }
            });
            
            if (!user) {
              const name = row['اسم العميل'] || row['العميل'] || 'عميل جديد (استيراد)';
              user = await prisma.users.create({
                data: {
                  name: name,
                  phone_number: phone,
                }
              });
            }
            clientId = user.id;
          }

          // 2. Prepare Quote Data
          const quoteData = {
            quote_number: parseInt(row['الرقم التسلسلي']) || null,
            client_user_id: clientId,
            created_by_user_id: 1, // Admin default
            number_of_elevators: parseInt(row['عدد المصاعد']) || 1,
            machine_type: row['نوع المكينة'] || null, // Text
            machine_position: trunc(row['وضع الماكينة'], 255),
            number_of_stops: parseInt(row['عدد الوقفات']) || null,
            load_kg: safeParseFloat(row['الحمولة']),
            number_of_persons: parseInt(row['عدد الاشخاص']) || null,
            number_of_entrances: parseInt(row['عدد جهات الدخول']) || null,
            stop_names: trunc(row['مسميات الوقفات'], 500),
            shaft_material: trunc(row['البئر - مبني من'], 255),
            shaft_internal_size: trunc(row['البئر - المقاس الداخلي'], 255),
            car_frame: trunc(row['الاطار الحامل للصاعدة'], 500),
            car_finish: trunc(row['الصاعدة - التشطيب'], 500),
            inside_car_dimensions: trunc(row['الصاعدة - المقاسات الداخلية'], 255),
            floor: trunc(row['الأرضية'], 255),
            roof: trunc(row['السقف'], 255),
            door_operation: row['طريقة تشغيل الأبواب'] || null, // Text
            door_dimensions: row['مقاسات الابواب'] || null, // Text
            inner_door: row['الباب الداخلي'] || null, // Text
            landing_door_main: trunc(row['لوحة الطلب الخارجية - الوقفة الرئيسية'], 255),
            landing_door_other: trunc(row['لوحة الطلب الخارجية - الوقفات الاخرى'], 255),
            guide_rail: trunc(row['سكك الصاعدة'], 500),
            counterweight_guide_rails: trunc(row['سكك ثقل الموازنة'], 500),
            traction_ropes: trunc(row['حبال الجر'], 500),
            traveling_cable: trunc(row['الكابل المرن'], 500),
            operation_method: trunc(row['جهاز تشغيل المصعد'], 255),
            electrical_current: trunc(row['التيار الكهربائي'], 100),
            cop: trunc(row['لوحة الطلب الداخلية COP'], 500),
            emergency_light: trunc(row['اضاءة الطوارئ'], 255),
            total_price: safeParseFloat(row['السعر الإجمالي']),
            discount_amount: safeParseFloat(row['مبلغ التخفيض']),
            price_details: row['تفاصيل السعر'] || null,
            supply_and_install: row['التوريد والتركيب'] || null,
            warranty_and_free_maintenance: row['الضمان والصيانة المجانية'] || null,
            preparatory_works: row['الأعمال التحضيرية'] || null,
            status_enum: mapStatus(row['حالة العرض'] || row['حالة الموافقة من العميل']),
          };

          // 3. Create Quote
          await prisma.elevator_quotes.create({
            data: quoteData
          });
          
          insertedCount++;
          if (insertedCount % 100 === 0) {
            console.log(`Inserted ${insertedCount} rows...`);
          }
        } catch (err) {
          console.error(`Failed to insert row ${row['الرقم التسلسلي']}:`, err.message);
        }
      }
      
      console.log(`\nImport complete! Successfully inserted ${insertedCount} rows.`);
    });
}

main()
  .catch(e => console.error(e))
  .finally(async () => {
    await prisma.$disconnect();
  });
