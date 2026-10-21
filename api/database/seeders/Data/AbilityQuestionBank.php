<?php

namespace Database\Seeders\Data;

/**
 * Authentic entrance examination question bank seeded from ENTRANCE EXAM - REVISED.pub
 * Covers General Information, Verbal Ability, Scientific Ability, Numerical Ability, and Logical Ability.
 */
class AbilityQuestionBank
{
    public static function forCode(string $code): array
    {
        return match ($code) {
            'VERBAL' => self::verbal(),
            'NUMERICAL' => self::numerical(),
            'LOGICAL' => self::logical(),
            'SCIENCE' => self::scientific(),
            'GENERAL' => self::general(),
            default => self::fallback($code),
        };
    }

    private static function mcq(string $stem, array $options, string $correct, string $difficulty = 'medium'): array
    {
        $keys = ['A', 'B', 'C', 'D'];
        $mapped = [];
        foreach (array_values($options) as $i => $text) {
            $mapped[] = ['key' => $keys[$i], 'text' => $text];
        }

        return [
            'stem' => $stem,
            'options' => $mapped,
            'correct' => $correct,
            'difficulty' => $difficulty,
        ];
    }

    /**
     * Verbal Ability / English (Questions 21 - 40 from ENTRANCE EXAM - REVISED)
     */
    private static function verbal(): array
    {
        return [
            self::mcq('I went ______ the street.', ['across', 'acrost', 'crossing', 'none of these'], 'A', 'easy'),
            self::mcq('Of the two pictures, I like this one _______.', ['good', 'better', 'most', 'none of these'], 'B', 'medium'),
            self::mcq('This is different ______ that.', ['from', 'than', 'in', 'none of these'], 'A', 'easy'),
            self::mcq('I _______ my work well.', ['do', 'done', 'did', 'none of these'], 'A', 'easy'),
            self::mcq('Yesterday I ________ down for a rest.', ['lays', 'lay', 'laid', 'none of these'], 'B', 'hard'),
            self::mcq('He __________ to do that kind of job.', ['ought not', 'doesn\'t ought', 'hadn\'t ought', 'none of these'], 'A', 'hard'),
            self::mcq('Mario is absent _______ the class.', ['on', 'from', 'at', 'none of these'], 'B', 'easy'),
            self::mcq('Sherwin runs ____________ the town of San Agustin.', ['at', 'through', 'from', 'none of these'], 'B', 'medium'),
            self::mcq('My birthday is, ________ Monday.', ['in', 'on', 'it', 'none of these'], 'B', 'easy'),
            self::mcq('___________ is a Christmas gift from my uncle.', ['These', 'This', 'Those', 'none of these'], 'B', 'easy'),
            self::mcq('I did not come _________ you did not allow me.', ['therefore', 'through', 'because', 'none of these'], 'C', 'easy'),
            self::mcq('She wrote a first-rate report. What does it say about the report?', ['Ordinary', 'Run of the mill', 'Excellent', 'Mediocre'], 'C', 'medium'),
            self::mcq('I lived with them _________ I was in College.', ['while', 'until', 'where', 'though'], 'A', 'medium'),
            self::mcq('The sun __________ brightly this morning.', ['shine', 'shines', 'shined', 'shining'], 'B', 'easy'),
            self::mcq('I have ___________ my term papers for English.', ['write', 'writing', 'written', 'wrote'], 'C', 'easy'),
            self::mcq('He __________ in bed for eleven days.', ['stay', 'staying', 'stood', 'stayed'], 'D', 'easy'),
            self::mcq('The soldiers fought ___________ in the battle.', ['fierce', 'fiercely', 'bitter', 'none of these'], 'B', 'easy'),
            self::mcq('"She was a Phantom of delight" means:', ['Lovely', 'Illusion', 'real', 'fake'], 'A', 'medium'),
            self::mcq('The longest epic ever written is:', ['Lam-ang', 'Mahabharata', 'Siegfried', 'Lament'], 'B', 'medium'),
            self::mcq('A long poem which depicts the adventure of a great hero, who reveals his country\'s aspirations:', ['Epic', 'Short story', 'Novel', 'Legend'], 'A', 'easy'),
        ];
    }

    /**
     * Numerical Ability / Mathematics (Questions 61 - 80 from ENTRANCE EXAM - REVISED)
     */
    private static function numerical(): array
    {
        return [
            self::mcq('80% of 65 =', ['530', '5.2', '52', '5.3'], 'C', 'easy'),
            self::mcq('5 is ______ of 20.', ['25%', '20%', '50%', '30%'], 'A', 'easy'),
            self::mcq('The population of a certain island was 5,000 in 1935. By 1960 it was increased by 50%. How many did the population increase?', ['2,500', '10,000', '7,500', '8,000'], 'A', 'medium'),
            self::mcq('Mr. Reyes had read 160 pages of his reviewer. He said this was 80% of the whole reviewer. How many pages were there in Mr. Reyes\' reviewer?', ['200 pages', '240 pages', '320 pages', '150 pages'], 'A', 'medium'),
            self::mcq('The oldest man on an island was born on June 20, 1902. How old was he on July 30, 1942?', ['40 years, 1 month and 10 days', '40 years, 2 months', '42 years, 1 month and 10 days', '41 years, 2 months and 2 days'], 'A', 'medium'),
            self::mcq('Subtract 27 5/14 from 43 1/6:', ['15', '16', '15 8/21', '15 17/21'], 'D', 'hard'),
            self::mcq('Multiply 17 5/8 by 128:', ['2,200', '2,305', '2,356', '2,256'], 'D', 'medium'),
            self::mcq('Nicky is a cell phone dealer who uses a mark-up rate of 7.5%. If he bought a new model phone for ₱12,000.00 from the manufacturer, how much will he sell it?', ['₱12,750.00', '₱12,800.00', '₱12,900.00', '₱21,000.00'], 'C', 'medium'),
            self::mcq('5.9 - 2.4 equals:', ['4.6', '3.5', '3.6', '4.5'], 'B', 'easy'),
            self::mcq('10 millimeters is equal to:', ['one decimeter', 'one centimeter', 'one meter', 'kilometer'], 'B', 'easy'),
            self::mcq('The storekeeper ordered a half gross of notebooks. How many notebooks did he order?', ['72 units', '82 units', '68 units', '96 units'], 'A', 'medium'),
            self::mcq('If a child weighs 50 kilograms, how many kilos is the child?', ['500 kilos', '5 kilos', '50 kilos', '0.5 kilos'], 'C', 'easy'),
            self::mcq('If you subtract -1 from +1 the result will be _______.', ['-2', '1', '0', '2'], 'D', 'easy'),
            self::mcq('The difference between one hundred five thousand eighty-four and ninety-three thousand seven hundred 9 is:', ['11,375', '12,131', '56,294', '56,375'], 'A', 'medium'),
            self::mcq('285 is 5% of:', ['5,700', '1,700', '7,350', '1,750'], 'A', 'medium'),
            self::mcq('The Roman numeral for 1151 is:', ['CLXI', 'MCLI', 'MVCI', 'LCXI'], 'B', 'easy'),
            self::mcq('A is 15 years old. B is one-third older. The number of years ago when B was twice as old as A is _________.', ['3', '5', '7.5', '10'], 'D', 'hard'),
            self::mcq('2,003 multiplied by 6.02 is _________.', ['8.32205', '4.09003', '12,058.06', '6.0005'], 'C', 'medium'),
            self::mcq('The ratio of 24 to 64 is ___________.', ['8:3', '24:100', '3:8', '64:100'], 'C', 'easy'),
            self::mcq('If the product of two terms is 25a²b² and one of the terms is 5ab, what is the other term?', ['5ab²', '5ab', '5a²b', '5a⁴b³'], 'B', 'easy'),
        ];
    }

    /**
     * Scientific Ability / General Science (Questions 41 - 60 from ENTRANCE EXAM - REVISED)
     */
    private static function scientific(): array
    {
        return [
            self::mcq('Which of the following is an example of physical change?', ['Burning metal', 'Changing of iodine crystals into a violet gas', 'Formation of two gases during electrolysis of water', 'Formation of black solid from heated iron and sulfur'], 'B', 'medium'),
            self::mcq('An example of a pure element:', ['Diamond', 'Brass', 'Nail', 'Alloy'], 'A', 'medium'),
            self::mcq('A process of change in matter from solid to gas:', ['Sublimation', 'Saturation', 'Evaporation', 'Condensation'], 'A', 'easy'),
            self::mcq('DNA stands for:', ['Deoxyribonucleic acid', 'Derybonucleic Acid', 'DeoNeurorobotic acid', 'None of these'], 'A', 'easy'),
            self::mcq('Oxygen is released into the atmosphere through:', ['Photosynthetic plants', 'Burning fossil fuels', 'Burning forest', 'Acid precipitation'], 'A', 'easy'),
            self::mcq('NaCl means:', ['Salt', 'Acid', 'Sodium Chloride', 'Sugar'], 'C', 'easy'),
            self::mcq('The unit of measurement for the intensity of sounds:', ['Hertz', 'Angstrom', 'Decibel', 'Frequency'], 'C', 'easy'),
            self::mcq('The theory of relativity was developed by:', ['Isaac Newton', 'Galileo Galilei', 'Albert Einstein', 'Aristotle'], 'C', 'easy'),
            self::mcq('The scientific study of the human mind and its functions:', ['Psychology', 'Geometry', 'Philosophy', 'Botany'], 'A', 'easy'),
            self::mcq('The force of attraction between different kinds of molecules is called force of:', ['Adhesion', 'Transmission', 'Cohesion', 'Deformation'], 'A', 'medium'),
            self::mcq('The process of spreading out spontaneously to occupy a space uniformly:', ['compression', 'Expansion', 'diffusion', 'Pressure'], 'C', 'medium'),
            self::mcq('Simplest duplication of a cell and all its parts:', ['symbiosis', 'mitosis', 'Metaphase', 'Meiosis'], 'B', 'medium'),
            self::mcq('The red tide phenomenon causes what particular marine organism to be poisonous for human consumption?', ['mollusk', 'earth worms', 'algae', 'arthropods'], 'A', 'medium'),
            self::mcq('Commonly used sugar in day to day life:', ['fructose', 'Sucrose', 'Glucose', 'lactose'], 'B', 'easy'),
            self::mcq('An acidic substance _____:', ['Donates protons', 'Neither donates nor accepts', 'Accepts protons', 'None of the above'], 'A', 'medium'),
            self::mcq('One is not present in an animal cell:', ['mitochondria', 'nucleolus', 'Chloroplast', 'plasma membrane'], 'C', 'easy'),
            self::mcq('An antidote would have a similar neutralizing effect if vinegar or citrus juice were not available:', ['Water', 'Raw egg white', 'Vegetable Oil', 'Milk'], 'D', 'hard'),
            self::mcq('A phenomenon associated with a change in direction of the equatorial current:', ['Tsunami', 'La Nina', 'Eutrophication', 'El Nino'], 'D', 'medium'),
            self::mcq('The smallest bones in the human body are found in which part?', ['Neck', 'Back', 'Ear', 'Fingers'], 'C', 'easy'),
            self::mcq('The heaviest organ in the human body:', ['Brain', 'Skin', 'Liver', 'Hair'], 'B', 'easy'),
        ];
    }

    /**
     * General Information (Questions 1 - 20 & General Civics from ENTRANCE EXAM - REVISED)
     */
    private static function general(): array
    {
        return [
            self::mcq('The place where Father Burgos, Gomez and Zamora were executed is ___.', ['Fort Santiago', 'Luneta/Bagumbayan', 'Madrid', 'Fort Aguinaldo'], 'B', 'easy'),
            self::mcq('Who is the father of the Philippine Independence?', ['Manuel L. Quezon', 'Emilio Aguinaldo', 'Sergio Osmeña', 'Elpidio Quirino'], 'B', 'easy'),
            self::mcq('It is a month of fasting among the Muslims nationwide ___________.', ['Zakat', 'Ramadan', 'Jihad', 'Police Power'], 'B', 'easy'),
            self::mcq('The founder of Katipunan was ___________.', ['Dr. Jose Rizal', 'Emilio Aguinaldo', 'Andres Bonifacio', 'Gregorio del Pilar'], 'C', 'easy'),
            self::mcq('Who is the 16th president of the Philippines?', ['Ferdinand Marcos Jr.', 'Ferdinand Marcos Sr.', 'Rodrigo R. Duterte', 'Benigno S. Aquino Jr.'], 'C', 'easy'),
            self::mcq('A republic act that allows foreign investors to invest up to 100% equity in domestic market enterprises.', ['RA 7042', 'RA 8179', 'RA 9253', 'RA 8214'], 'A', 'medium'),
            self::mcq('The WHO declared the outbreak a public health emergency of International concern on:', ['January 30, 2020', 'March 15, 2020', 'February 15, 2020', 'January 15, 2020'], 'A', 'medium'),
            self::mcq('The COVID-19 virus originated in:', ['Beijing, China', 'Shanghai, China', 'Wuhan, China', 'Guangzhou, China'], 'C', 'easy'),
            self::mcq('Are smokers most likely to develop severe diseases with COVID-19?', ['Yes', 'No', 'Most likely', 'Never'], 'A', 'easy'),
            self::mcq('The national task force against COVID-19 (NDRRMC or MDRRMC) is under what branch of Government?', ['Executive', 'Judiciary', 'DILG', 'Legislative'], 'A', 'medium'),
            self::mcq('Can you be arrested without a warrant of arrest?', ['No, if you are a minor', 'Yes, if you were reported to have committed a crime', 'No, if you are more than 60 yrs. old', 'Yes, if you are in the act of committing a crime'], 'D', 'medium'),
            self::mcq('A task force organized by the executive of the Government of the Philippines to respond to affairs concerning emerging infectious diseases in the country:', ['MDRRMC', 'DOH', 'IATF', 'WHO'], 'C', 'easy'),
            self::mcq('Scurvy is caused by the lack of:', ['Vitamin E', 'Vitamin D', 'Vitamin C', 'Vitamin A'], 'C', 'easy'),
            self::mcq('Goods that are increasingly demanded when income increases:', ['Substitute goods', 'Prestige goods', 'Normal goods', 'Inferior goods'], 'C', 'medium'),
            self::mcq('The nuclear family consists of:', ['Grandfathers and Mothers', 'Sons, Daughters and Cousins', 'Mother, Father, Sons & Daughters', 'Sons, Daughters and Sons-in-law'], 'C', 'easy'),
            self::mcq('The total number of provinces in the Philippines:', ['71', '81', '65', '90'], 'B', 'easy'),
            self::mcq('The Philippines is a __________.', ['Republican State', 'Dominican State', 'Commonwealth State', 'Federal State'], 'A', 'easy'),
            self::mcq('Free Trade means:', ['Levels of taxing is mutual between countries', 'Goods are all imported', 'Goods come and Taxed', 'Government restrict entry of identified goods'], 'A', 'medium'),
            self::mcq('What stress can do to your mental health:', ['Increase risk of mental health problems', 'Promotes anxiety and depression', 'Sleep problems', 'Bodily complaints'], 'A', 'easy'),
            self::mcq('A compulsory contribution to the government to defray the expenses incurred in the common interest is ______________.', ['Tax', 'Fee', 'Budget', 'Fund'], 'A', 'easy'),
            self::mcq('Subdivision of Municipalities are called __________.', ['Provinces', 'Cities', 'Barangays', 'Town'], 'C', 'easy'),
            self::mcq('The Philippines was discovered by Ferdinand Magellan on ______________.', ['March 16, 1521', 'March 21, 1516', 'September 20, 1519', 'September 7, 1921'], 'A', 'easy'),
            self::mcq('The execution of Dr. Jose Rizal in Bagumbayan was on ____________.', ['June 19, 1861', 'November 30, 1896', 'December 30, 1869', 'December 30, 1896'], 'D', 'easy'),
            self::mcq('The oldest city in the Philippines is ____________.', ['Iloilo', 'Cebu', 'Manila', 'Pasay'], 'B', 'easy'),
            self::mcq('It is considered the basic unit of society.', ['Household', 'Clan', 'Family', 'Tribe'], 'C', 'easy'),
            self::mcq('An estimate for financing the government during a definite period.', ['Government expenses', 'Government budget', 'Government income', 'Government interest'], 'B', 'medium'),
            self::mcq('This agency offers the individual many opportunities for intelligent reading.', ['Church', 'Community Assemblies', 'Public Library', 'Parent-Teacher Association'], 'C', 'easy'),
            self::mcq('This Act is prohibiting the manufacture and sale of adulterated and misbranded foods or drugs:', ['Agriculture Adjustment Act', 'Securities Act', 'Food and Drugs Act', 'Dangerous Drugs Act'], 'C', 'medium'),
        ];
    }

    /**
     * Logical Ability (Abstract reasoning, pattern sequences, analogies, and syllogisms)
     */
    private static function logical(): array
    {
        return [
            self::mcq('Find the odd one out: Apple, Banana, Carrot, Mango', ['Apple', 'Banana', 'Carrot', 'Mango'], 'C', 'easy'),
            self::mcq('Complete the series: 5, 10, 20, 40, ____', ['50', '60', '80', '100'], 'C', 'easy'),
            self::mcq('If all roses are flowers and some flowers fade quickly, which is true?', ['All roses fade quickly', 'Some roses may fade quickly', 'No roses fade', 'Roses are not flowers'], 'B', 'medium'),
            self::mcq('Dog is to puppy as cat is to ____.', ['kitten', 'cub', 'calf', 'foal'], 'A', 'easy'),
            self::mcq('Which comes next: AZ, BY, CX, ____?', ['DW', 'EV', 'DU', 'DV'], 'A', 'medium'),
            self::mcq('If Monday is coded as 123456, what is DAY?', ['456', '145', '345', '156'], 'A', 'hard'),
            self::mcq('Find the missing number: 3, 6, 11, 18, ____', ['25', '27', '29', '30'], 'B', 'medium'),
            self::mcq('Which statement is a valid conclusion? All A are B. All B are C.', ['All A are C', 'All C are A', 'Some A are not C', 'No A are C'], 'A', 'medium'),
            self::mcq('Odd one out: Square, Circle, Triangle, Rectangle', ['Square', 'Circle', 'Triangle', 'Rectangle'], 'B', 'easy'),
            self::mcq('If 2 = 6, 3 = 12, 4 = 20, then 5 =', ['25', '30', '35', '40'], 'B', 'hard'),
            self::mcq('Book is to reading as fork is to ____.', ['drawing', 'writing', 'eating', 'cooking'], 'C', 'easy'),
            self::mcq('Complete: 1, 4, 9, 16, 25, ____', ['30', '35', '36', '49'], 'C', 'easy'),
            self::mcq('Which does not belong: Red, Blue, Green, Soft', ['Red', 'Blue', 'Green', 'Soft'], 'D', 'easy'),
            self::mcq('If north becomes east, then west becomes ____.', ['north', 'south', 'east', 'west'], 'B', 'medium'),
            self::mcq('Arrange in logical order: 1. Seed 2. Tree 3. Fruit 4. Flower', ['1-4-3-2', '1-2-4-3', '2-1-4-3', '1-4-2-3'], 'B', 'medium'),
            self::mcq('Find the analogy: Night : Day :: Dark : ____', ['Black', 'Light', 'Moon', 'Shadow'], 'B', 'easy'),
            self::mcq('Which number is different: 2, 3, 5, 7, 9, 11?', ['3', '7', '9', '11'], 'C', 'medium'),
            self::mcq('If A > B and B > C, then:', ['C > A', 'A > C', 'A = C', 'Cannot say'], 'B', 'easy'),
            self::mcq('Series: Z, X, V, T, ____', ['R', 'S', 'Q', 'P'], 'A', 'medium'),
            self::mcq('If "PEN" is coded as 35, how is "PIN" coded? (P=16, E=5, N=14)', ['39', '40', '45', '48'], 'A', 'hard'),
            self::mcq('Odd one out: Teacher, Doctor, Engineer, Classroom', ['Teacher', 'Doctor', 'Engineer', 'Classroom'], 'D', 'easy'),
            self::mcq('Which is the mirror of 3:00 on a clock face conceptually closest to?', ['8:00', '9:00', '10:00', '6:00'], 'B', 'hard'),
        ];
    }

    private static function fallback(string $code): array
    {
        $items = [];
        for ($i = 1; $i <= 20; $i++) {
            $items[] = self::mcq(
                "Sample question {$i} for {$code}. Which option is correct?",
                ['Option A', 'Option B', 'Option C', 'Option D'],
                'A',
                $i % 3 === 0 ? 'hard' : ($i % 2 === 0 ? 'medium' : 'easy')
            );
        }

        return $items;
    }
}
